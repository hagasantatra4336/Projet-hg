<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'activités du système (central et superadmin) : qui a fait quoi, quand,
 * depuis quel appareil. Les entrées sont en lecture seule : aucun effacement n'est proposé.
 */
class ActiviteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activite::query()->latest('id');

        if ($request->filled('categorie') && array_key_exists((string) $request->input('categorie'), Activite::categories())) {
            $query->where('categorie', (string) $request->input('categorie'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        $this->appliquerPeriode($query, (string) $request->input('periode', 'tout'));

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->input('search')).'%';

            // whereLike : insensible à la casse sur PostgreSQL et SQLite
            $query->where(function (Builder $q) use ($term) {
                $q->whereLike('user_nom', $term)
                    ->orWhereLike('description', $term)
                    ->orWhereLike('cible', $term)
                    ->orWhereLike('ip', $term);
            });
        }

        $page = $query->paginate(20)->through(fn (Activite $activite) => [
            'id' => $activite->id,
            'user_id' => $activite->user_id,
            'user_nom' => $activite->user_nom,
            'role' => $activite->role,
            'categorie' => $activite->categorie,
            'categorie_libelle' => Activite::categories()[$activite->categorie] ?? $activite->categorie,
            'action' => $activite->action,
            'description' => $activite->description,
            'cible' => $activite->cible,
            'ip' => $activite->ip,
            'user_agent' => $activite->user_agent,
            'cree_le' => $activite->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'categories' => collect(Activite::categories())
                ->map(fn (string $libelle, string $valeur) => ['value' => $valeur, 'label' => $libelle])
                ->values(),
            'compteurs' => [
                'total' => Activite::count(),
                'aujourdhui' => Activite::whereDate('created_at', now()->toDateString())->count(),
                'connexions' => Activite::where('categorie', Activite::CATEGORIE_CONNEXION)->count(),
                'utilisateurs' => Activite::whereNotNull('user_id')->distinct()->count('user_id'),
            ],
        ]);
    }

    /** Période rapide : aujourd'hui, 7 derniers jours, 30 derniers jours ou tout l'historique. */
    private function appliquerPeriode(Builder $query, string $periode): void
    {
        $debut = match ($periode) {
            'jour' => now()->startOfDay(),
            'semaine' => now()->subDays(7)->startOfDay(),
            'mois' => now()->subDays(30)->startOfDay(),
            default => null,
        };

        if ($debut !== null) {
            $query->where('created_at', '>=', $debut);
        }
    }
}
