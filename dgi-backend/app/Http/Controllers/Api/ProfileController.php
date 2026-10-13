<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Centre;
use App\Models\User;
use App\Notifications\ProfileChangeNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileController extends Controller
{
    private const TTL_MINUTES = 30;

    /** Le résultat d'une demande (confirmée, annulée...) reste consultable par la page d'attente pendant ce délai. */
    private const RESULT_MINUTES = 15;
    private const REQUEST_PREFIX = 'profile-request:';

    private const LABELS = [
        'nom' => 'nom',
        'telephone' => 'téléphone',
        'adresse' => 'adresse',
        'centre_id' => 'centre',
        'password' => 'mot de passe',
    ];

    /** Étape 1 (connecté) : valide la demande, la mémorise et envoie le lien de confirmation. */
    public function requestChange(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $changes = [];
        foreach (['nom', 'telephone', 'adresse'] as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : null;
            $value = $value === '' ? null : $value;

            if ($value !== $user->{$field}) {
                $changes[$field] = $value;
            }
        }

        if ($request->has('centre_id')) {
            $centreId = isset($data['centre_id']) ? (int) $data['centre_id'] : null;
            $currentCentreId = $user->centre_id !== null ? (int) $user->centre_id : null;

            if ($centreId !== $currentCentreId) {
                $role = $user->role;

                if ($role === Role::Admin) {
                    throw ValidationException::withMessages([
                        'centre_id' => "Un admin ne peut pas changer de centre lui-même : contactez un responsable central ou un super admin.",
                    ]);
                }

                // Seuls central et superadmin peuvent n'avoir aucun centre.
                if ($centreId === null && ! in_array($role, [Role::Central, Role::Superadmin], true)) {
                    throw ValidationException::withMessages([
                        'centre_id' => 'Le centre est obligatoire.',
                    ]);
                }

                $changes['centre_id'] = $centreId;
            }
        }

        if (filled($data['password'] ?? null)) {
            if (! filled($data['current_password'] ?? null) || ! Hash::check($data['current_password'], (string) $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Le mot de passe actuel est incorrect.',
                ]);
            }

            $changes['password'] = Hash::make($data['password']); // jamais de mot de passe en clair dans le cache
        }

        if ($changes === []) {
            throw ValidationException::withMessages([
                'nom' => 'Aucune modification à enregistrer.',
            ]);
        }

        // Une seule demande en attente par utilisateur : la nouvelle remplace l'ancienne.
        $pointerKey = 'profile-pending:' . $user->id;
        if ($previous = Cache::get($pointerKey)) {
            $ancienne = Cache::pull($this->changeKey($previous));
            if (is_array($ancienne)) {
                $this->marquerDemande($ancienne['request_id'] ?? null, 'cancelled');
            }
        }

        $token = Str::random(64);
        $hash = hash('sha256', $token); // seul le hash du jeton est gardé côté serveur
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        // Identifiant secret de la demande : la page d'attente s'en sert pour suivre son état (voir changeStatus).
        $requestId = Str::random(48);
        $champs = array_map(fn (string $f) => self::LABELS[$f], array_keys($changes));

        Cache::put($this->changeKey($hash), ['user_id' => $user->id, 'changes' => $changes, 'request_id' => $requestId], $expiresAt);
        Cache::put($pointerKey, $hash, $expiresAt);
        Cache::put(self::REQUEST_PREFIX . $requestId, [
            'status' => 'pending',
            'user_id' => $user->id,
            'champs' => $champs,
            'expires_at' => $expiresAt->toIso8601String(),
            'password_changed' => false,
        ], $expiresAt->copy()->addMinutes(self::RESULT_MINUTES));

        $url = $this->frontendBase($request) . '/profile/confirm?token=' . $token;

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Log::warning('MAIL_MAILER=' . config('mail.default') . " : l'e-mail de confirmation n'est PAS réellement envoyé (voir storage/logs/laravel.log).");
        }

        try {
            $user->notify(new ProfileChangeNotification((string) $user->nom, $champs, $url, self::TTL_MINUTES));
        } catch (Throwable $e) {
            Cache::forget($this->changeKey($hash));
            Cache::forget($pointerKey);
            Cache::forget(self::REQUEST_PREFIX . $requestId);
            Log::error('Envoi du mail de confirmation impossible : ' . $e->getMessage());

            return response()->json([
                'message' => config('app.debug')
                    ? "Envoi de l'e-mail impossible : " . $e->getMessage()
                    : "Impossible d'envoyer l'e-mail pour le moment.",
            ], 500);
        }

        $response = [
            'message' => 'Un e-mail de confirmation vient de vous être envoyé.',
            'email' => $user->email,
            'request_id' => $requestId,
            'champs' => $champs,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        // MODE DÉVELOPPEMENT UNIQUEMENT (APP_DEBUG=true ET APP_ENV=local) : lien aussi renvoyé dans la réponse.
        if (config('app.debug') && app()->environment('local')) {
            $response['debug_confirm_url'] = $url;
        }

        return response()->json($response);
    }

    /** Étape 2 (public, depuis le lien reçu par e-mail) : applique les modifications. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        // utilisation du lien qu'une seule fois
        $pending = Cache::pull($this->changeKey(hash('sha256', $data['token'])));
        $user = is_array($pending) ? User::find($pending['user_id']) : null;

        if (! $user) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        }

        Cache::forget('profile-pending:' . $user->id);

        $changes = $pending['changes'];
        $requestId = $pending['request_id'] ?? null;

        if (array_key_exists('centre_id', $changes)) {
            if ($user->role === Role::Admin) {
                $message = "Un admin ne peut pas changer de centre lui-même.";
                $this->marquerDemande($requestId, 'failed', ['message' => $message]);

                return response()->json(['message' => $message], 422);
            }

            if ($changes['centre_id'] !== null && ! Centre::whereKey($changes['centre_id'])->exists()) {
                $message = "Le centre choisi n'existe plus. Refaites une demande depuis votre profil.";
                $this->marquerDemande($requestId, 'failed', ['message' => $message]);

                return response()->json(['message' => $message], 422);
            }
        }

        $passwordChanged = array_key_exists('password', $changes);

        // Mise à jour via le query builder : le mot de passe est déjà haché, on évite tout double hachage.
        User::whereKey($user->id)->update($changes);

        if ($passwordChanged) {
            $user->tokens()->delete(); // déconnecte toutes les sessions : reconnexion obligatoire
        }

        // La page d'attente (ouverte dans un autre onglet) lit ce résultat et se met à jour toute seule.
        $this->marquerDemande($requestId, 'confirmed', ['password_changed' => $passwordChanged]);

        return response()->json([
            'message' => 'Modifications confirmées.',
            'password_changed' => $passwordChanged,
        ]);
    }

    /**
     * État d'une demande, pour la page d'attente (public : l'identifiant de 48 caractères aléatoires est le secret,
     * il n'est renvoyé qu'à l'utilisateur connecté qui a fait la demande). Aucune donnée personnelle n'est exposée.
     * status : pending | confirmed | cancelled | failed | expired
     */
    public function changeStatus(string $requestId): JsonResponse
    {
        $record = Cache::get(self::REQUEST_PREFIX . $requestId);

        if (! is_array($record)) {
            return response()->json(['status' => 'expired']);
        }

        $status = (string) $record['status'];
        if ($status === 'pending' && now()->greaterThan(Carbon::parse($record['expires_at']))) {
            $status = 'expired';
        }

        return response()->json([
            'status' => $status,
            'champs' => $record['champs'] ?? [],
            'expires_at' => $record['expires_at'] ?? null,
            'password_changed' => (bool) ($record['password_changed'] ?? false),
            'message' => $record['message'] ?? null,
        ]);
    }

    /** Annule la demande en attente de l'utilisateur connecté (bouton « Annuler » de la page d'attente). */
    public function cancelChange(Request $request): JsonResponse
    {
        $pointerKey = 'profile-pending:' . $request->user()->id;

        if ($hash = Cache::pull($pointerKey)) {
            $pending = Cache::pull($this->changeKey($hash));
            if (is_array($pending)) {
                $this->marquerDemande($pending['request_id'] ?? null, 'cancelled');
            }
        }

        return response()->json(['message' => 'Demande annulée.']);
    }

    /**
     * Adresse du front utilisée dans le lien de l'e-mail : celle depuis laquelle l'utilisateur a fait sa demande
     * (en-tête Origin envoyé par le navigateur), à condition qu'elle fasse partie des origines de confiance de
     * config/cors.php. Ainsi le lien s'ouvre aussi depuis un smartphone quand l'application a été ouverte avec
     * l'adresse du PC. Sinon : FRONTEND_URL.
     */
    private function frontendBase(Request $request): string
    {
        $origin = rtrim((string) $request->headers->get('Origin', ''), '/');

        if ($origin !== '' && $this->originDeConfiance($origin)) {
            return $origin;
        }

        return rtrim((string) config('dgi.frontend_url', 'http://localhost:5173'), '/');
    }

    private function originDeConfiance(string $origin): bool
    {
        if (in_array($origin, (array) config('cors.allowed_origins', []), true)) {
            return true;
        }

        foreach ((array) config('cors.allowed_origins_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Met à jour l'état d'une demande suivie par la page d'attente (sans effet si elle n'existe plus). */
    private function marquerDemande(?string $requestId, string $status, array $extra = []): void
    {
        if ($requestId === null) {
            return;
        }

        $key = self::REQUEST_PREFIX . $requestId;
        $record = Cache::get($key);

        if (is_array($record)) {
            Cache::put($key, array_merge($record, $extra, ['status' => $status]), now()->addMinutes(self::RESULT_MINUTES));
        }
    }

    private function changeKey(string $hash): string
    {
        return 'profile-change:' . $hash;
    }
}
