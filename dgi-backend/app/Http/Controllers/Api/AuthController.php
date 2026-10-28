<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Centre;
use App\Models\DemandeInscription;
use App\Models\Fonction;
use App\Models\User;
use App\Notifications\DemandeInscriptionNotification;
use App\Services\OtpMailer;
use App\Services\OtpService;
use App\Support\FrontendUrl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Inscription d'un agent en 4 étapes :
 *   1. infos → une DEMANDE est créée en base et l'approbateur est prévenu (aucun code encore) ;
 *   2. l'approbateur approuve (ou refuse) depuis « Demandes d'inscription » ;
 *   3. si approuvée, un code OTP valable 5 minutes est envoyé → l'agent le saisit ;
 *   4. l'agent choisit son mot de passe : le compte est créé.
 *
 * L'approbateur dépend de la FONCTION demandée, selon la hiérarchie agent → chef de centre → central → superadmin :
 *   - fonction « agent »        → le chef du centre choisi (à défaut le central, puis le superadmin) ;
 *   - fonction « chef de centre » → le central (à défaut le superadmin) ;
 *   - fonction « central »      → le superadmin.
 * La fonction de superadmin n'est jamais proposée à l'inscription (comptes créés depuis l'administration).
 */
class AuthController extends Controller
{
    private const SETUP_TTL_MINUTES = 30;
    private const DEMANDE_TTL_DAYS = 7;

    public function __construct(
        private readonly OtpService $otp,
        private readonly OtpMailer $mailer,
    ) {
    }

    /** Étape 1 : crée la demande d'inscription et prévient l'approbateur (selon la fonction). */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'im' => ['nullable', 'string', 'max:50', 'unique:users,im'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'centre_id' => ['required', 'integer', 'exists:centres,id'],
            // Inscription publique : agent, chef de centre ou central. Jamais superadmin.
            'fonction_id' => [
                'required',
                'integer',
                Rule::exists('fonctions', 'id')->whereIn('role', [
                    Role::Agent->value,
                    Role::Admin->value,
                    Role::Central->value,
                ]),
            ],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Cet e-mail est déjà utilisé.',
            ]);
        }

        if (filled($data['im'] ?? null) && DemandeInscription::query()
            ->where('im', $data['im'])
            ->whereIn('statut', [DemandeInscription::EN_ATTENTE, DemandeInscription::APPROUVEE])
            ->exists()) {
            throw ValidationException::withMessages([
                'im' => 'Ce matricule est déjà utilisé par une autre demande en cours.',
            ]);
        }

        // Une demande en attente (ou déjà approuvée, code pas encore saisi) est réutilisée :
        // l'agent qui recharge la page ne crée pas de doublon.
        $existante = DemandeInscription::with(['centre', 'fonction'])
            ->where('email', $data['email'])
            ->whereIn('statut', [DemandeInscription::EN_ATTENTE, DemandeInscription::APPROUVEE])
            ->latest('id')
            ->first();

        if ($existante !== null) {
            $statut = $existante->statutEffectif();

            if ($statut === DemandeInscription::EXPIREE) {
                $existante->update(['statut' => DemandeInscription::EXPIREE]);
            } else {
                // Route publique : ne jamais renvoyer ici le request_id (ni le nom ou l'approbateur) de
                // la demande existante. Il suffirait de connaître un e-mail pour obtenir ce secret,
                // lire la demande via /register/status et l'annuler via /register/cancel. Le navigateur
                // qui a créé la demande conserve son request_id dans localStorage et la suit seul.
                return response()->json([
                    'message' => "Une demande existe déjà pour cet e-mail. Reprenez-la depuis le navigateur où elle a été créée, ou contactez l'administration pour la réinitialiser.",
                    'statut' => $statut,
                    'reprise_locale_uniquement' => true,
                ]);
            }
        }

        $fonction = Fonction::findOrFail($data['fonction_id']);
        $approbation = $this->approbateurs((int) $data['centre_id'], $fonction->role);

        if ($approbation['users']->isEmpty()) {
            throw ValidationException::withMessages([
                'fonction_id' => "Aucun responsable n'est disponible pour approuver cette fonction. Contactez l'administration centrale.",
            ]);
        }

        $demande = DemandeInscription::create([
            'request_id' => Str::random(48),
            'nom' => $data['nom'],
            'email' => $data['email'],
            'im' => $data['im'] ?? null,
            'telephone' => $data['telephone'] ?? null,
            'adresse' => $data['adresse'] ?? null,
            'centre_id' => $data['centre_id'],
            'fonction_id' => $data['fonction_id'],
            'statut' => DemandeInscription::EN_ATTENTE,
            'niveau_approbation' => $approbation['niveau'],
            'expires_at' => now()->addDays(self::DEMANDE_TTL_DAYS),
        ]);

        $demande->load(['centre', 'fonction']);
        $this->notifierApprobateurs($demande, $approbation['users'], $request);

        // Le superadmin n'est jamais nommé côté inscription : on parle de l'administration centrale.
        $destination = $approbation['niveau'] === DemandeInscription::NIVEAU_CHEF
            ? "au chef du centre {$demande->centre?->nom}"
            : "à l'administration centrale";

        return $this->payloadDemande(
            $demande,
            "Votre demande a été envoyée {$destination}. Vous recevrez un code dès son approbation.",
            201
        );
    }

    /** Suivi public de la demande : le request_id de 48 caractères est le secret, il n'est jamais renvoyé par /register. */
    public function signupStatus(string $requestId): JsonResponse
    {
        $demande = DemandeInscription::with(['centre', 'fonction', 'traitePar'])
            ->where('request_id', $requestId)
            ->first();

        if ($demande === null) {
            return response()->json([
                'statut' => 'introuvable',
                'message' => 'Demande introuvable ou supprimée.',
            ], 404);
        }

        $statut = $demande->statutEffectif();
        $secondesAvantRenvoi = 0;

        if ($statut === DemandeInscription::APPROUVEE && $demande->otp_envoye_le !== null) {
            $prochain = $demande->otp_envoye_le->copy()->addSeconds(OtpService::DELAI_RENVOI_SECONDES);
            $secondesAvantRenvoi = max(0, (int) now()->diffInSeconds($prochain, false));
        }

        return response()->json([
            'request_id' => $demande->request_id,
            'statut' => $statut,
            'nom' => $demande->nom,
            'email' => $demande->email,
            'centre' => $demande->centre ? ['id' => $demande->centre->id, 'nom' => $demande->centre->nom] : null,
            'fonction' => $demande->fonction?->nom,
            'niveau_approbation' => $demande->niveau_approbation,
            'approbateur' => $demande->traitePar?->nom,
            'motif_refus' => $statut === DemandeInscription::REFUSEE ? $demande->motif_refus : null,
            'expires_at' => $demande->expires_at?->toIso8601String(),
            'otp_expires_at' => $statut === DemandeInscription::APPROUVEE && $demande->otp_hash !== null
                ? $demande->otp_expires_at?->toIso8601String()
                : null,
            'code_verifie' => $demande->codeVerifie(),
            'renvoi_possible' => $statut === DemandeInscription::APPROUVEE
                && $demande->otp_renvois < OtpService::MAX_RENVOIS,
            'secondes_avant_renvoi' => $secondesAvantRenvoi,
        ]);
    }

    /** Étape 3 : nouveau code (uniquement après approbation, délai de 60 s entre deux envois). */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'size:48'],
        ]);

        $demande = DemandeInscription::where('request_id', $data['request_id'])->first();

        if ($demande === null || $demande->statutEffectif() !== DemandeInscription::APPROUVEE) {
            return response()->json([
                'message' => "Votre demande n'est pas encore approuvée.",
            ], 422);
        }

        if ($demande->otp_renvois >= OtpService::MAX_RENVOIS) {
            return response()->json([
                'message' => 'Trop de renvois. Contactez votre chef de centre.',
            ], 422);
        }

        if ($demande->otp_envoye_le !== null) {
            $prochain = $demande->otp_envoye_le->copy()->addSeconds(OtpService::DELAI_RENVOI_SECONDES);

            if ($prochain->isFuture()) {
                return response()->json([
                    'message' => 'Patientez avant de demander un nouveau code.',
                    'retry_after' => max(1, (int) now()->diffInSeconds($prochain, false)),
                ], 429);
            }
        }

        $code = $this->otp->generer();

        try {
            $this->mailer->envoyer(
                $demande->email,
                $demande->nom,
                $code,
                OtpService::TTL_INSCRIPTION_MINUTES,
                'approbation'
            );
        } catch (Throwable $e) {
            Log::error('Renvoi OTP inscription impossible : ' . $e->getMessage());

            return response()->json([
                'message' => "Impossible d'envoyer l'e-mail pour le moment. Réessayez dans un instant.",
            ], 502);
        }

        // Un nouveau code remplace l'ancien ; une vérification déjà faite est annulée.
        $demande->update([
            'otp_hash' => $this->otp->hacher($demande->email, $code),
            'otp_expires_at' => now()->addMinutes(OtpService::TTL_INSCRIPTION_MINUTES),
            'otp_attempts' => 0,
            'otp_renvois' => $demande->otp_renvois + 1,
            'otp_envoye_le' => now(),
            'setup_hash' => null,
            'setup_expires_at' => null,
        ]);

        $payload = [
            'message' => 'Un nouveau code a été envoyé. Il est valable 5 minutes.',
            'otp_expires_at' => $demande->otp_expires_at?->toIso8601String(),
        ];

        if ($this->modeDeveloppement()) {
            $payload['debug_otp'] = $code;
        }

        return response()->json($payload);
    }

    /** Vérifie le code reçu après approbation et renvoie un jeton temporaire. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'size:48'],
            'otp_code' => ['required', 'digits:6'],
        ]);

        // Même principe que pour la connexion : le compteur survit aux renvois de code.
        $cleEchecs = 'otp-echecs:signup:' . sha1($data['request_id']);
        if (RateLimiter::tooManyAttempts($cleEchecs, 10)) {
            return response()->json([
                'message' => 'Trop de tentatives. Réessayez dans ' . RateLimiter::availableIn($cleEchecs) . ' secondes.',
            ], 429);
        }

        $demande = DemandeInscription::where('request_id', $data['request_id'])->first();

        if ($demande === null
            || $demande->statutEffectif() !== DemandeInscription::APPROUVEE
            || $demande->otp_hash === null
            || $demande->otp_expires_at === null
            || now()->greaterThan($demande->otp_expires_at)) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        if ($demande->otp_attempts >= OtpService::MAX_TENTATIVES) {
            $demande->update(['otp_hash' => null, 'otp_expires_at' => null]);

            return response()->json([
                'message' => 'Trop de tentatives. Demandez un nouveau code.',
            ], 422);
        }

        if (! $this->otp->verifier($demande->email, $data['otp_code'], $demande->otp_hash)) {
            RateLimiter::hit($cleEchecs, 900);
            $demande->increment('otp_attempts');

            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        RateLimiter::clear($cleEchecs);
        $setupToken = Str::random(40);

        $demande->update([
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'setup_hash' => hash('sha256', $setupToken),
            'setup_expires_at' => now()->addMinutes(self::SETUP_TTL_MINUTES),
        ]);

        return response()->json([
            'message' => 'E-mail vérifié.',
            'setup_token' => $setupToken,
            'email' => $demande->email,
        ]);
    }

    /** Étape 4 : crée le compte (approbation + code vérifié + setup_token obligatoires). */
    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'setup_token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $demande = DemandeInscription::where('email', $data['email'])
            ->where('statut', DemandeInscription::APPROUVEE)
            ->latest('id')
            ->first();

        $valide = $demande !== null
            && $demande->setup_hash !== null
            && $demande->setup_expires_at !== null
            && now()->lessThanOrEqualTo($demande->setup_expires_at)
            && hash_equals($demande->setup_hash, hash('sha256', $data['setup_token']));

        if (! $valide || User::where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => "Session expirée. Veuillez recommencer l'inscription.",
            ], 422);
        }

        // La fonction a pu changer de rôle depuis la demande : on revérifie qu'elle reste ouverte
        // à l'inscription (agent, chef de centre ou central ; jamais superadmin).
        if (! Fonction::where('id', $demande->fonction_id)->whereIn('role', [
            Role::Agent->value,
            Role::Admin->value,
            Role::Central->value,
        ])->exists()) {
            $demande->update(['statut' => DemandeInscription::ANNULEE]);

            return response()->json([
                'message' => "Cette fonction n'est plus ouverte à l'inscription. Recommencez avec une autre fonction.",
            ], 422);
        }

        $user = User::create([
            'nom' => $demande->nom,
            'email' => $demande->email,
            'im' => $demande->im,
            'telephone' => $demande->telephone,
            'adresse' => $demande->adresse,
            'centre_id' => $demande->centre_id,
            'fonction_id' => $demande->fonction_id,
            'password' => $data['password'], 
            'email_verified_at' => now(),
        ]);

        $demande->update([
            'statut' => DemandeInscription::COMPTE_CREE,
            'setup_hash' => null,
            'setup_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'token' => $user->createToken('auth')->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /** L'agent annule sa demande (avant la création du compte). */
    public function cancelSignup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'size:48'],
        ]);

        $demande = DemandeInscription::where('request_id', $data['request_id'])->first();

        if ($demande === null) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }

        if (! in_array($demande->statutEffectif(), [DemandeInscription::EN_ATTENTE, DemandeInscription::APPROUVEE], true)) {
            return response()->json(['message' => 'Cette demande ne peut plus être annulée.'], 422);
        }

        $demande->update([
            'statut' => DemandeInscription::ANNULEE,
            'otp_hash' => null,
            'otp_expires_at' => null,
            'setup_hash' => null,
            'setup_expires_at' => null,
        ]);

        return response()->json(['message' => 'Demande annulée.']);
    }

    /**
     * Connexion, étape 1 : vérifie e-mail + mot de passe, puis envoie un code OTP par e-mail.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Limitation par COMPTE, en plus du throttle par IP de la route : des tentatives réparties
        // sur plusieurs adresses (ou derrière un proxy) ne contournent plus le compteur.
        $cleEchecs = 'login-echecs:' . sha1(Str::lower($data['email']));
        if (RateLimiter::tooManyAttempts($cleEchecs, 5)) {
            return response()->json([
                'message' => 'Trop de tentatives pour ce compte. Réessayez dans ' . RateLimiter::availableIn($cleEchecs) . ' secondes.',
            ], 429);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($cleEchecs, 900); // 15 minutes

            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        RateLimiter::clear($cleEchecs);

        return $this->sendLoginOtp($user, 'Un code de connexion a été envoyé par e-mail.');
    }

    /** Connexion, étape 2 : vérifie l'OTP et renvoie le jeton Sanctum. */
    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'digits:6'],
        ]);

        // Compteur cumulé sur 15 minutes, volontairement NON remis à zéro par « Renvoyer le code » :
        // sinon un cycle renvoi/tentatives illimité annulerait l'effet de MAX_TENTATIVES (5 essais par code).
        $cleEchecs = 'otp-echecs:login:' . sha1(Str::lower($data['email']));
        if (RateLimiter::tooManyAttempts($cleEchecs, 10)) {
            return response()->json([
                'message' => 'Trop de tentatives. Veuillez vous reconnecter dans ' . RateLimiter::availableIn($cleEchecs) . ' secondes.',
            ], 429);
        }

        $key = $this->loginKey($data['email']);
        $state = Cache::get($key);

        if (! is_array($state) || now()->timestamp > $state['expires_at']) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        if ($state['attempts'] >= OtpService::MAX_TENTATIVES) {
            Cache::forget($key);

            return response()->json([
                'message' => 'Trop de tentatives. Veuillez vous reconnecter.',
            ], 422);
        }

        if (! $this->otp->verifier($data['email'], $data['otp_code'], $state['otp'])) {
            RateLimiter::hit($cleEchecs, 900);
            $state['attempts']++;
            Cache::put($key, $state, Carbon::createFromTimestamp($state['expires_at']));

            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        RateLimiter::clear($cleEchecs);
        Cache::forget($key); // code à usage unique

        $user = User::find($state['user_id']);

        if (! $user) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        return response()->json([
            'token' => $user->createToken('auth')->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /** Renvoie un nouveau code de connexion (uniquement si l'étape 1 a réussi récemment). */
    public function resendLoginOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $state = Cache::get($this->loginKey($data['email']));
        $user = is_array($state) && now()->timestamp <= $state['expires_at']
            ? User::find($state['user_id'])
            : null;

        if (! $user) {
            return response()->json([
                'message' => 'Session expirée. Veuillez vous reconnecter.',
            ], 422);
        }

        return $this->sendLoginOtp($user, 'Un nouveau code a été envoyé.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /** Utilisateur connecté (le front l'appelle au chargement pour connaître le rôle). */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * Listes de l'inscription publique : centres (avec l'indication « a_chef ») + fonctions ouvertes
     * à l'inscription (agent, chef de centre, central). Le superadmin n'est jamais proposé :
     * ses comptes se créent dans la partie Administration.
     */
    public function signupOptions(): JsonResponse
    {
        $chefs = User::query()
            ->whereNotNull('centre_id')
            ->whereHas('fonction', fn ($q) => $q->where('role', Role::Admin->value))
            ->selectRaw('centre_id, count(*) as total')
            ->groupBy('centre_id')
            ->pluck('total', 'centre_id');

        return response()->json([
            'centres' => Centre::orderBy('nom')->get(['id', 'nom'])->map(fn (Centre $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'a_chef' => (int) ($chefs[$c->id] ?? 0) > 0,
            ]),
            'fonctions' => Fonction::query()
                ->whereIn('role', [Role::Agent->value, Role::Admin->value, Role::Central->value])
                ->orderBy('nom')
                ->get(['id', 'nom', 'role'])
                ->map(fn (Fonction $f) => [
                    'id' => $f->id,
                    'nom' => $f->nom,
                    'role' => $f->role->value,
                ]),
        ]);
    }

    // ------------------------------------------------------------------
    //  Outils internes
    // ------------------------------------------------------------------

    /**
     * Approbateur de la demande, selon la FONCTION demandée et la hiérarchie :
     *   agent → chef du centre (sinon central, sinon superadmin) ;
     *   chef de centre → central (sinon superadmin) ;
     *   central → superadmin.
     *
     * @return array{users: \Illuminate\Support\Collection<int, User>, niveau: string}
     */
    private function approbateurs(int $centreId, Role $roleDemandee): array
    {
        if ($roleDemandee === Role::Agent) {
            $chefs = User::query()
                ->where('centre_id', $centreId)
                ->whereHas('fonction', fn ($q) => $q->where('role', Role::Admin->value))
                ->get();

            if ($chefs->isNotEmpty()) {
                return ['users' => $chefs, 'niveau' => DemandeInscription::NIVEAU_CHEF];
            }

            return $this->approbateursCentraux();
        }

        if ($roleDemandee === Role::Admin) {
            return $this->approbateursCentraux();
        }

        return [
            'users' => User::query()
                ->whereHas('fonction', fn ($q) => $q->where('role', Role::Superadmin->value))
                ->get(),
            'niveau' => DemandeInscription::NIVEAU_SUPERADMIN,
        ];
    }

    /** @return array{users: \Illuminate\Support\Collection<int, User>, niveau: string} */
    private function approbateursCentraux(): array
    {
        $centraux = User::query()
            ->whereHas('fonction', fn ($q) => $q->where('role', Role::Central->value))
            ->get();

        if ($centraux->isNotEmpty()) {
            return ['users' => $centraux, 'niveau' => DemandeInscription::NIVEAU_CENTRAL];
        }

        return [
            'users' => User::query()
                ->whereHas('fonction', fn ($q) => $q->where('role', Role::Superadmin->value))
                ->get(),
            'niveau' => DemandeInscription::NIVEAU_SUPERADMIN,
        ];
    }

    /** E-mail aux approbateurs : après la réponse, pour ne pas ralentir l'inscription. */
    private function notifierApprobateurs(DemandeInscription $demande, iterable $approbateurs, Request $request): void
    {
        $url = FrontendUrl::base($request) . '/admin/demandes';

        app()->terminating(function () use ($demande, $approbateurs, $url): void {
            foreach ($approbateurs as $approbateur) {
                try {
                    Notification::route('mail', $approbateur->email)
                        ->notify(new DemandeInscriptionNotification($demande, $url));
                } catch (Throwable $e) {
                    Log::warning("Notification du chef de centre {$approbateur->email} impossible : " . $e->getMessage());
                }
            }
        });
    }

    /** Connexion : même principe que l'inscription, état en cache lié à l'utilisateur. */
    private function sendLoginOtp(User $user, string $successMessage): JsonResponse
    {
        $code = $this->otp->generer();
        $expiresAt = now()->addMinutes(OtpService::TTL_CONNEXION_MINUTES);

        Cache::put($this->loginKey($user->email), [
            'user_id' => $user->id,
            'otp' => $this->otp->hacher($user->email, $code),
            'attempts' => 0,
            'expires_at' => $expiresAt->timestamp,
        ], $expiresAt);

        // Envoi APRÈS la réponse (la page du code s'affiche tout de suite) ; l'échec est journalisé.
        $this->mailer->envoyerApresReponse(
            $user->email,
            (string) $user->nom,
            $code,
            OtpService::TTL_CONNEXION_MINUTES,
            'connexion'
        );

        return $this->reponseOtp($successMessage, $code);
    }

    private function reponseOtp(string $message, string $code): JsonResponse
    {
        $payload = ['message' => $message, 'otp_required' => true];

        if ($this->modeDeveloppement()) {
            $payload['debug_otp'] = $code;
        }

        $response = response()->json($payload);

        // Content-Length explicite : le navigateur considère la réponse terminée sans attendre
        // la fin du script (utile avec « php artisan serve », qui n'a pas de fastcgi_finish_request).
        $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));

        return $response;
    }

    private function payloadDemande(DemandeInscription $demande, string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'request_id' => $demande->request_id,
            'statut' => $demande->statutEffectif(),
            'nom' => $demande->nom,
            'email' => $demande->email,
            'approbateur' => [
                'niveau' => $demande->niveau_approbation,
                'centre' => $demande->centre?->nom,
            ],
            'expires_at' => $demande->expires_at?->toIso8601String(),
            'otp_expires_at' => $demande->statut === DemandeInscription::APPROUVEE
                ? $demande->otp_expires_at?->toIso8601String()
                : null,
            'code_verifie' => $demande->codeVerifie(),
        ], $status);
    }

    private function modeDeveloppement(): bool
    {
        return (bool) config('app.debug') && app()->environment('local');
    }

    private function loginKey(string $email): string
    {
        return 'login:' . sha1(Str::lower($email));
    }

    private function userPayload(User $user): array
    {
        return (new UserResource($user->loadMissing(['centre', 'fonction'])))->resolve();
    }
}
