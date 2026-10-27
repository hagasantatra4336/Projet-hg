<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\DemandeInscription;
use App\Services\ReglesDetection;
use App\Services\VerrouAlerte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compteurs affichés dans la navigation : ce qui attend une action de l'utilisateur connecté.
 *
 *  - defaillances : dossiers (contribuables) ayant au moins une alerte libre « à traiter » ;
 *  - traitements  : dossiers que l'utilisateur a lui-même pris et qui sont encore en cours ;
 *  - demandes     : demandes d'inscription en attente qu'il peut approuver.
 *
 * Les périmètres reprennent ceux des écrans correspondants : centre pour agent et admin,
 * règles désactivées masquées, et hiérarchie d'approbation des inscriptions.
 */
class NotificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        $aTraiter = $this->alertesVisibles($me);
        VerrouAlerte::libres($aTraiter);

        $enCours = $this->alertesVisibles($me);
        VerrouAlerte::verrouillees($enCours);
        $enCours->where('alertes.pris_par', $me->id);

        $compteurs = [
            'defaillances' => $aTraiter->distinct()->count('alertes.contribuable_id'),
            'traitements' => $enCours->distinct()->count('alertes.contribuable_id'),
            'demandes' => $this->demandesEnAttente($me),
        ];

        return response()->json($compteurs + ['total' => array_sum($compteurs)]);
    }

    /** Alertes visibles par l'utilisateur (même périmètre que la page Défaillances). */
    private function alertesVisibles($me): Builder
    {
        $query = Alerte::query();
        ReglesDetection::limiterAlertes($query);

        if (! in_array($me->role, [Role::Agent, Role::Admin], true)) {
            return $query;
        }

        // Sans centre, agent et admin ne voient aucune alerte (comme sur la page)
        if ($me->centre_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('contribuable', fn (Builder $q) => $q->where('centre_id', $me->centre_id));
    }

    /** Demandes en attente que l'utilisateur peut réellement approuver (voir DemandeInscriptionController). */
    private function demandesEnAttente($me): int
    {
        $query = DemandeInscription::query()
            ->where('statut', DemandeInscription::EN_ATTENTE)
            ->where('expires_at', '>', now());

        return match ($me->role) {
            Role::Admin => $me->centre_id === null ? 0 : $query
                ->where('centre_id', $me->centre_id)
                ->whereHas('fonction', fn (Builder $q) => $q->where('role', Role::Agent->value))
                ->count(),
            Role::Central => $query
                ->whereHas('fonction', fn (Builder $q) => $q->whereIn('role', [Role::Agent->value, Role::Admin->value]))
                ->count(),
            Role::Superadmin => $query->count(),
            default => 0,
        };
    }
}
