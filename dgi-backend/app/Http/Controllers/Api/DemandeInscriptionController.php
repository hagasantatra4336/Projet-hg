<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\DemandeInscription;
use App\Models\Fonction;
use App\Models\User;
use App\Notifications\DemandeRefuseeNotification;
use App\Services\OtpMailer;
use App\Services\OtpService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Boîte de réception des demandes d'inscription, réservée aux rôles admin / central / superadmin.
 *
 *  - chef de centre (admin) : voit et traite uniquement les demandes d'AGENTS de SON centre ;
 *  - central : voit tout, approuve les agents et les chefs de centre ;
 *  - superadmin : voit tout et approuve en dernier ressort (dont les demandes de fonction central).
 *
 * L'approbateur dépend de la fonction demandée (voir AuthController).
 * Approuver envoie immédiatement à l'agent un code OTP valable 5 minutes.
 * Refuser n'envoie aucun code et bloque la création du compte.
 */
class DemandeInscriptionController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly OtpMailer $mailer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $statut = (string) $request->input('statut', DemandeInscription::EN_ATTENTE);

        $query = DemandeInscription::with(['centre', 'fonction', 'traitePar'])->latest('id');

        if ($me->role === Role::Admin) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $query->where('centre_id', $me->centre_id)
                ->whereHas('fonction', fn ($q) => $q->where('role', Role::Agent->value));
        }

        if ($statut === DemandeInscription::EN_ATTENTE) {
            $query->where('statut', DemandeInscription::EN_ATTENTE)->where('expires_at', '>', now());
        } elseif ($statut === DemandeInscription::EXPIREE) {
            $query->where('statut', DemandeInscription::EN_ATTENTE)->where('expires_at', '<=', now());
        } elseif ($statut !== 'tous') {
            $query->where('statut', $statut);
        }

        $enAttente = DemandeInscription::query()
            ->where('statut', DemandeInscription::EN_ATTENTE)
            ->where('expires_at', '>', now())
            ->when($me->role === Role::Admin, fn ($q) => $q
                ->where('centre_id', $me->centre_id)
                ->whereHas('fonction', fn ($f) => $f->where('role', Role::Agent->value)))
            ->count();

        $page = $query->paginate(20)->through(fn (DemandeInscription $d) => $this->payload($d));

        return response()->json($page->toArray() + ['en_attente_total' => $enAttente]);
    }

    /** Approuve la demande et envoie le code OTP (valable 5 minutes). */
    public function approuver(Request $request, DemandeInscription $demande): JsonResponse
    {
        $this->assertPeutTraiter($request->user(), $demande);

        if ($demande->statutEffectif() !== DemandeInscription::EN_ATTENTE) {
            return response()->json([
                'message' => "Cette demande n'est plus en attente.",
            ], 422);
        }

        if (User::where('email', $demande->email)->exists()) {
            $demande->update([
                'statut' => DemandeInscription::REFUSEE,
                'motif_refus' => 'Un compte existe déjà avec cet e-mail.',
                'traite_par' => $request->user()->id,
                'traite_le' => now(),
            ]);

            return response()->json([
                'message' => 'Un compte existe déjà avec cet e-mail : la demande a été refusée.',
            ], 422);
        }

        if (! Fonction::where('id', $demande->fonction_id)->whereIn('role', [
            Role::Agent->value,
            Role::Admin->value,
            Role::Central->value,
        ])->exists()) {
            return response()->json([
                'message' => "La fonction demandée n'est plus ouverte à l'inscription.",
            ], 422);
        }

        $code = $this->otp->generer();

        // On n'enregistre l'approbation QUE si l'e-mail est réellement parti :
        // sinon le chef voit l'erreur et peut réessayer, l'agent n'attend pas un code fantôme.
        try {
            $this->mailer->envoyer(
                $demande->email,
                $demande->nom,
                $code,
                OtpService::TTL_INSCRIPTION_MINUTES,
                'approbation'
            );
        } catch (Throwable $e) {
            Log::error("Approbation de la demande #{$demande->id} : envoi OTP impossible — " . $e->getMessage());

            return response()->json([
                'message' => "La demande est toujours en attente : l'e-mail n'a pas pu être envoyé. Réessayez dans un instant.",
            ], 502);
        }

        $demande->update([
            'statut' => DemandeInscription::APPROUVEE,
            'traite_par' => $request->user()->id,
            'traite_le' => now(),
            'motif_refus' => null,
            'otp_hash' => $this->otp->hacher($demande->email, $code),
            'otp_expires_at' => now()->addMinutes(OtpService::TTL_INSCRIPTION_MINUTES),
            'otp_attempts' => 0,
            'otp_renvois' => 1,
            'otp_envoye_le' => now(),
            'setup_hash' => null,
            'setup_expires_at' => null,
        ]);

        $demande->load(['centre', 'fonction', 'traitePar']);

        $payload = [
            'message' => "Demande approuvée. Un code valable 5 minutes a été envoyé à {$demande->email}.",
            'demande' => $this->payload($demande),
        ];

        if (config('app.debug') && app()->environment('local')) {
            $payload['debug_otp'] = $code;
        }

        return response()->json($payload);
    }

    /** Refuse la demande : aucun code OTP, aucun compte. */
    public function refuser(Request $request, DemandeInscription $demande): JsonResponse
    {
        $this->assertPeutTraiter($request->user(), $demande);

        if ($demande->statutEffectif() !== DemandeInscription::EN_ATTENTE) {
            return response()->json([
                'message' => "Cette demande n'est plus en attente.",
            ], 422);
        }

        $data = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        $demande->update([
            'statut' => DemandeInscription::REFUSEE,
            'motif_refus' => $data['motif'] ?? null,
            'traite_par' => $request->user()->id,
            'traite_le' => now(),
            'otp_hash' => null,
            'otp_expires_at' => null,
            'setup_hash' => null,
            'setup_expires_at' => null,
        ]);

        $url = FrontendUrl::base($request) . '/signup';

        // Information de l'agent : après la réponse, sans bloquer le chef.
        app()->terminating(function () use ($demande, $url): void {
            try {
                Notification::route('mail', $demande->email)
                    ->notify(new DemandeRefuseeNotification($demande->nom, $demande->motif_refus, $url));
            } catch (Throwable $e) {
                Log::warning("Notification de refus à {$demande->email} impossible : " . $e->getMessage());
            }
        });

        return response()->json(['message' => 'Demande refusée. Aucun compte ne sera créé.']);
    }

    // ------------------------------------------------------------------
    //  Outils internes
    // ------------------------------------------------------------------

    /**
     * Hiérarchie : chaque niveau ne traite que les demandes qui lui reviennent.
     *  - chef de centre (admin) : uniquement les demandes d'AGENTS de son centre ;
     *  - central : agents et chefs de centre (pas les demandes de fonction central) ;
     *  - superadmin : tout (dernier niveau, y compris les demandes de fonction central).
     * Un agent n'a jamais accès à ces routes (EnsureRole), cette méthode est la seconde barrière.
     */
    private function assertPeutTraiter(?User $me, DemandeInscription $demande): void
    {
        abort_if($me === null || $me->role === null, 403, "Vous n'avez pas les droits nécessaires.");

        $demande->loadMissing('fonction');
        $roleDemande = $demande->fonction?->role;

        abort_if($roleDemande === null, 422, "La fonction de cette demande n'existe plus.");

        $autorise = match ($me->role) {
            Role::Superadmin => true,
            Role::Central => in_array($roleDemande, [Role::Agent, Role::Admin], true),
            Role::Admin => $roleDemande === Role::Agent
                && $me->centre_id !== null
                && (int) $me->centre_id === (int) $demande->centre_id,
            default => false,
        };

        abort_unless($autorise, 403, "Cette demande ne relève pas de votre niveau d'approbation.");
    }

    /** @return array<string, mixed> */
    private function payload(DemandeInscription $d): array
    {
        return [
            'id' => $d->id,
            'nom' => $d->nom,
            'email' => $d->email,
            'im' => $d->im,
            'telephone' => $d->telephone,
            'adresse' => $d->adresse,
            'centre' => $d->centre ? ['id' => $d->centre->id, 'nom' => $d->centre->nom] : null,
            'fonction' => $d->fonction ? ['id' => $d->fonction->id, 'nom' => $d->fonction->nom] : null,
            'statut' => $d->statutEffectif(),
            'niveau_approbation' => $d->niveau_approbation,
            'motif_refus' => $d->motif_refus,
            'traite_par' => $d->traitePar?->nom,
            'traite_le' => $d->traite_le?->toIso8601String(),
            'otp_envoye_le' => $d->otp_envoye_le?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'expires_at' => $d->expires_at?->toIso8601String(),
        ];
    }
}
