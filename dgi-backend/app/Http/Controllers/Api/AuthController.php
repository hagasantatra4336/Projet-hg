<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Centre;
use App\Models\Fonction;
use App\Notifications\OtpNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Inscription en 3 étapes SANS écrire l'OTP en base de données :
 * l'état de l'inscription (infos + OTP haché) vit dans le cache, avec une durée de vie courte.
 * L'utilisateur n'est créé dans la table "users" qu'à l'étape 3.
 */
class AuthController extends Controller
{
    private const OTP_TTL_MINUTES = 10;
    private const SETUP_TTL_MINUTES = 30;
    private const MAX_OTP_ATTEMPTS = 5;

    /** Étape 1 : mémorise les infos (cache) et envoie l'OTP par e-mail. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'im' => ['nullable', 'string', 'max:50', 'unique:users,im'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'centre_id' => ['required', 'integer', 'exists:centres,id'],
            // Inscription publique : uniquement les fonctions de rôle "agent".
            // Les rôles admin / central / superadmin sont attribués par un responsable.
            'fonction_id' => [
                'required',
                'integer',
                Rule::exists('fonctions', 'id')->where('role', Role::Agent->value),
            ],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Cet e-mail est déjà utilisé.',
            ]);
        }

        return $this->sendOtp(
            $data['email'],
            $this->profile($data),
            'Un code de vérification a été envoyé par e-mail.'
        );
    }

    /** Étape 2 : vérifie l'OTP et renvoie un jeton temporaire pour l'étape 3. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'digits:6'],
        ]);

        $state = $this->getState($data['email']);

        if (! $state || $state['verified']) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        if ($state['attempts'] >= self::MAX_OTP_ATTEMPTS) {
            Cache::forget($this->key($data['email']));

            return response()->json([
                'message' => 'Trop de tentatives. Demandez un nouveau code ou recommencez.',
            ], 422);
        }

        if (! Hash::check($data['otp_code'], $state['otp'])) {
            $state['attempts']++;
            $this->saveState($data['email'], $state);

            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        // OTP correct : on le remplace par un jeton temporaire exigé à l'étape 3.
        $setupToken = Str::random(40);

        $state['verified'] = true;
        $state['otp'] = null;
        $state['setup'] = Hash::make($setupToken);
        $state['expires_at'] = now()->addMinutes(self::SETUP_TTL_MINUTES)->timestamp;
        $this->saveState($data['email'], $state);

        return response()->json([
            'message' => 'E-mail vérifié.',
            'setup_token' => $setupToken,
        ]);
    }

    /** Renvoie un nouveau code OTP (tant que l'e-mail n'est pas vérifié). */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $state = $this->getState($data['email']);

        if (! $state || $state['verified']) {
            return response()->json([
                'message' => "Session expirée. Veuillez recommencer l'inscription.",
            ], 422);
        }

        return $this->sendOtp($data['email'], $this->profile($state), 'Un nouveau code a été envoyé.');
    }

    /** Étape 3 : crée le compte (e-mail vérifié + setup_token obligatoires). */
    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'setup_token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $state = $this->getState($data['email']);

        $valid = $state
            && $state['verified']
            && $state['setup']
            && Hash::check($data['setup_token'], $state['setup']);

        if (! $valid || User::where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => "Session expirée. Veuillez recommencer l'inscription.",
            ], 422);
        }

        // La fonction a pu changer de rôle depuis l'étape 1 : on revérifie qu'elle est toujours "agent".
        if (! Fonction::where('id', $state['fonction_id'])->where('role', Role::Agent->value)->exists()) {
            Cache::forget($this->key($data['email']));

            return response()->json([
                'message' => "Session expirée. Veuillez recommencer l'inscription.",
            ], 422);
        }

        $user = User::create($this->profile($state) + [
            'email' => $data['email'],
            'password' => $data['password'], // haché automatiquement (cast "hashed" du modèle)
            'email_verified_at' => now(),
        ]);

        Cache::forget($this->key($data['email']));

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'token' => $user->createToken('auth')->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Connexion, étape 1 : vérifie e-mail + mot de passe, puis envoie un code OTP par e-mail.
     * Aucun jeton n'est émis ici : il n'est délivré qu'après validation du code (étape 2).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        return $this->sendLoginOtp($user, 'Un code de connexion a été envoyé par e-mail.');
    }

    /** Connexion, étape 2 : vérifie l'OTP et renvoie le jeton Sanctum. */
    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'digits:6'],
        ]);

        $key = $this->loginKey($data['email']);
        $state = Cache::get($key);

        if (! is_array($state) || now()->timestamp > $state['expires_at']) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        if ($state['attempts'] >= self::MAX_OTP_ATTEMPTS) {
            Cache::forget($key);

            return response()->json([
                'message' => 'Trop de tentatives. Veuillez vous reconnecter.',
            ], 422);
        }

        if (! Hash::check($data['otp_code'], $state['otp'])) {
            $state['attempts']++;
            Cache::put($key, $state, Carbon::createFromTimestamp($state['expires_at']));

            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

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

    /** Listes de l'inscription publique : tous les centres + les fonctions de rôle "agent". */
    public function signupOptions(): JsonResponse
    {
        return response()->json([
            'centres' => Centre::orderBy('nom')->get(['id', 'nom']),
            'fonctions' => Fonction::where('role', Role::Agent->value)->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    // ------------------------------------------------------------------
    //  Outils internes
    // ------------------------------------------------------------------

    /** Inscription : génère l'OTP, le garde haché dans le cache (10 min) et l'envoie par e-mail. */
    private function sendOtp(string $email, array $profile, string $successMessage): JsonResponse
    {
        $code = $this->generateCode();

        $this->saveState($email, $profile + [
            'otp' => Hash::make($code),
            'verified' => false,
            'setup' => null,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)->timestamp,
        ]);

        return $this->deliverOtp($email, $profile['nom'] ?? '', $code, 'inscription', $successMessage);
    }

    /** Connexion : même principe, avec un état séparé (clé "login:...") lié à l'utilisateur. */
    private function sendLoginOtp(User $user, string $successMessage): JsonResponse
    {
        $code = $this->generateCode();
        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);

        Cache::put($this->loginKey($user->email), [
            'user_id' => $user->id,
            'otp' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->timestamp,
        ], $expiresAt);

        return $this->deliverOtp($user->email, (string) $user->nom, $code, 'connexion', $successMessage);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Envoie l'e-mail APRÈS avoir répondu au navigateur.
     *
     * Avant, l'envoi SMTP (connexion TLS + authentification + remise du message, souvent 3 à 15 s)
     * bloquait la requête : l'utilisateur attendait la fin de l'envoi pour voir l'écran du code.
     * Maintenant la réponse part tout de suite et l'e-mail est envoyé juste après, dans la même
     * requête PHP (aucun "queue:work" à lancer). Si l'envoi échoue, l'erreur est écrite dans
     * storage/logs/laravel.log et l'utilisateur peut cliquer sur « Renvoyer le code ».
     */
    private function deliverOtp(
        string $email,
        string $nom,
        string $code,
        string $contexte,
        string $successMessage,
    ): JsonResponse {
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Log::warning('MAIL_MAILER=' . config('mail.default') . " : l'e-mail OTP n'est PAS réellement envoyé (voir storage/logs/laravel.log).");
        }

        app()->terminating(function () use ($email, $nom, $code, $contexte): void {
            try {
                // Destinataire = e-mail de l'utilisateur ; expéditeur = MAIL_FROM_ADDRESS du .env
                Notification::route('mail', $email)
                    ->notify(new OtpNotification($nom, $code, self::OTP_TTL_MINUTES, $contexte));
            } catch (Throwable $e) {
                Log::error('Envoi OTP impossible : ' . $e->getMessage());
            }
        });

        $payload = ['message' => $successMessage, 'otp_required' => true];

        // MODE DÉVELOPPEMENT UNIQUEMENT (APP_DEBUG=true ET APP_ENV=local) :
        // le code est aussi renvoyé dans la réponse. En production, ce champ n'est JAMAIS envoyé.
        if (config('app.debug') && app()->environment('local')) {
            $payload['debug_otp'] = $code;
        }

        $response = response()->json($payload);

        // Content-Length explicite : le navigateur considère la réponse terminée sans attendre
        // la fin du script (utile avec « php artisan serve », qui n'a pas de fastcgi_finish_request).
        $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));

        return $response;
    }

    private function loginKey(string $email): string
    {
        return 'login:' . sha1(Str::lower($email));
    }

    private function key(string $email): string
    {
        return 'signup:' . sha1(Str::lower($email));
    }

    /** Retourne l'état de l'inscription, ou null s'il n'existe pas / a expiré. */
    private function getState(string $email): ?array
    {
        $state = Cache::get($this->key($email));

        if (! is_array($state) || now()->timestamp > $state['expires_at']) {
            return null;
        }

        return $state;
    }

    private function saveState(string $email, array $state): void
    {
        Cache::put($this->key($email), $state, Carbon::createFromTimestamp($state['expires_at']));
    }

    private function userPayload(User $user): array
    {
        return (new UserResource($user->loadMissing(['centre', 'fonction'])))->resolve();
    }

    /** Champs de profil mémorisés pendant l'inscription (cache) puis écrits dans "users". */
    private function profile(array $source): array
    {
        return [
            'nom' => $source['nom'],
            'im' => $source['im'] ?? null,
            'telephone' => $source['telephone'] ?? null,
            'adresse' => $source['adresse'] ?? null,
            'centre_id' => $source['centre_id'],
            'fonction_id' => $source['fonction_id'],
        ];
    }
}