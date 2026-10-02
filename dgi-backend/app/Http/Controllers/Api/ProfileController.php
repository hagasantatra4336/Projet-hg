<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ProfileChangeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Espace utilisateur : modification de SES informations (nom, téléphone, adresse) et de son mot de passe.
 *
 * Rien n'est appliqué tout de suite : la demande est gardée dans le cache (30 min) et un lien de
 * confirmation à usage unique est envoyé à l'adresse e-mail du compte. Les modifications ne sont
 * écrites en base qu'au clic de confirmation. Pas de code OTP ici.
 *
 * L'e-mail, l'IM, le centre et la fonction ne sont PAS modifiables par l'utilisateur lui-même
 * (ils se changent depuis Administration > Utilisateurs).
 */
class ProfileController extends Controller
{
    private const TTL_MINUTES = 30;

    private const LABELS = [
        'nom' => 'nom',
        'telephone' => 'téléphone',
        'adresse' => 'adresse',
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
            Cache::forget($this->changeKey($previous));
        }

        $token = Str::random(64);
        $hash = hash('sha256', $token); // seul le hash du jeton est gardé côté serveur
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put($this->changeKey($hash), ['user_id' => $user->id, 'changes' => $changes], $expiresAt);
        Cache::put($pointerKey, $hash, $expiresAt);

        $url = rtrim((string) config('dgi.frontend_url', 'http://localhost:5173'), '/')
            . '/profile/confirm?token=' . $token;

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Log::warning('MAIL_MAILER=' . config('mail.default') . " : l'e-mail de confirmation n'est PAS réellement envoyé (voir storage/logs/laravel.log).");
        }

        try {
            $champs = array_map(fn (string $f) => self::LABELS[$f], array_keys($changes));
            $user->notify(new ProfileChangeNotification((string) $user->nom, $champs, $url, self::TTL_MINUTES));
        } catch (Throwable $e) {
            Cache::forget($this->changeKey($hash));
            Cache::forget($pointerKey);
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

        // pull() lit ET supprime : le lien ne peut servir qu'une fois
        $pending = Cache::pull($this->changeKey(hash('sha256', $data['token'])));
        $user = is_array($pending) ? User::find($pending['user_id']) : null;

        if (! $user) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        }

        Cache::forget('profile-pending:' . $user->id);

        $changes = $pending['changes'];
        $passwordChanged = array_key_exists('password', $changes);

        // Mise à jour via le query builder : le mot de passe est déjà haché, on évite tout double hachage.
        User::whereKey($user->id)->update($changes);

        if ($passwordChanged) {
            $user->tokens()->delete(); // déconnecte toutes les sessions : reconnexion obligatoire
        }

        return response()->json([
            'message' => 'Modifications confirmées.',
            'password_changed' => $passwordChanged,
        ]);
    }

    private function changeKey(string $hash): string
    {
        return 'profile-change:' . $hash;
    }
}
