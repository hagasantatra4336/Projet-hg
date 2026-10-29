<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ouverture d'une session (jeton Sanctum) avec sa politique d'appareils :
 *   - agent et admin : deux appareils simultanés, le plus ancien est fermé au troisième ;
 *   - central et superadmin : un seul appareil, toute nouvelle connexion ferme la précédente.
 * Pour un rôle à session unique, les dossiers que le compte tenait sont libérés : l'appareil qui
 * les avait pris n'a plus de jeton et ne pourrait plus les rendre.
 */
class SessionsAppareils
{
    /** Libellé lisible de l'appareil, stocké comme nom du jeton. */
    public function libelle(Request $request): string
    {
        $ua = (string) $request->userAgent();

        $navigateur = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };

        $systeme = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'système inconnu',
        };

        return $navigateur . ' · ' . $systeme;
    }

    /** Nombre d'appareils connectés simultanément autorisés pour ce rôle. */
    public function maximum(User $user): int
    {
        return in_array($user->role, [Role::Central, Role::Superadmin], true) ? 1 : 2;
    }

    /** Crée le jeton, ferme les sessions en trop et retourne le jeton en clair. */
    public function ouvrir(User $user, Request $request): string
    {
        $token = $user->createToken($this->libelle($request))->plainTextToken;

        $this->fermerEnTrop($user);

        return $token;
    }

    /** Sessions actives du compte, la plus récente d'abord. */
    public function actives(User $user)
    {
        return $user->tokens()->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    private function fermerEnTrop(User $user): void
    {
        $maximum = $this->maximum($user);
        $enTrop = $this->actives($user)->slice($maximum);

        if ($enTrop->isEmpty()) {
            return;
        }

        PersonalAccessToken::whereKey($enTrop->pluck('id')->all())->delete();

        if ($maximum === 1) {
            Alerte::query()
                ->where('pris_par', $user->id)
                ->update(['pris_par' => null, 'pris_le' => null]);
        }
    }
}
