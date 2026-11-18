<?php

namespace App\Services;

use App\Models\Activite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Écriture du journal d'activités et calcul du statut « en ligne ».
 *
 * Le statut ne repose sur aucune colonne supplémentaire : Sanctum met à jour « last_used_at »
 * à chaque requête authentifiée. Un utilisateur est donc considéré en ligne tant que son jeton
 * a servi récemment ; une déconnexion supprime le jeton et le repasse immédiatement hors ligne.
 */
class JournalActivite
{
    /** Fenêtre de présence : au-delà, la session est considérée fermée (onglet fermé sans déconnexion). */
    public const PRESENCE_MINUTES = 15;

    private ?bool $tableDisponible = null;

    /**
     * @param  string  $categorie  une des constantes Activite::CATEGORIE_*
     * @param  string|null  $cible  élément concerné (nom d'utilisateur, centre, règle...)
     */
    public function enregistrer(
        Request $request,
        string $action,
        string $categorie,
        string $description,
        ?string $cible = null,
        ?User $auteur = null,
    ): ?Activite {
        if (! $this->tableDisponible()) {
            return null; // application pas encore migrée : le journal ne doit jamais bloquer une action
        }

        $user = $auteur ?? $request->user();

        try {
            return Activite::create([
                'user_id' => $user?->id,
                'user_nom' => mb_substr($user?->nom ?? 'Système', 0, 120),
                'role' => $user?->role?->value,
                'categorie' => $categorie,
                'action' => $action,
                'description' => $description,
                'cible' => $cible !== null && $cible !== '' ? mb_substr($cible, 0, 160) : null,
                'methode' => $request->method(),
                'chemin' => mb_substr('/'.ltrim($request->path(), '/'), 0, 191),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent() !== null ? mb_substr($request->userAgent(), 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // Le journal ne doit jamais faire échouer l'action qu'il décrit.
            Log::warning('Journal d’activité non enregistré : '.$e->getMessage(), [
                'action' => $action,
                'user_id' => $user?->id,
            ]);

            return null;
        }
    }

    /** Vrai si le jeton a servi dans la fenêtre de présence. */
    public static function estEnLigne(?string $derniereActivite): bool
    {
        if ($derniereActivite === null) {
            return false;
        }

        return Carbon::parse($derniereActivite)->greaterThanOrEqualTo(now()->subMinutes(self::PRESENCE_MINUTES));
    }

    private function tableDisponible(): bool
    {
        return $this->tableDisponible ??= Schema::hasTable('activites');
    }
}
