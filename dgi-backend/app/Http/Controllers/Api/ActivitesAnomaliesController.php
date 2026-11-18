<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\DossierHistorique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivitesAnomaliesController extends Controller
{
    private const ACTIONS = [
        DossierHistorique::PRISE => 'Prise en charge',
        DossierHistorique::REPRISE => 'Reprise du dossier',
        DossierHistorique::AFFECTATION => 'Assignation',
        DossierHistorique::LIBERATION => 'Libération',
        DossierHistorique::CLOTURE => 'Clôture',
        DossierHistorique::REOUVERTURE => 'Réouverture',
    ];

    private const STATUTS = [
        Alerte::A_TRAITER => 'À traiter',
        'en_traitement' => 'En traitement',
        Alerte::TRAITEE => 'Traitée',
        Alerte::REGULARISEE => 'Régularisée',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', Rule::in(array_keys(self::ACTIONS))],
            'periode' => ['nullable', Rule::in(['tout', 'jour', 'semaine', 'mois'])],
        ]);

        $me = $request->user();
        $query = DossierHistorique::query()
            ->with(['contribuable.centre'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($me->role === Role::Admin) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $query->whereHas('contribuable', fn (Builder $q) => $q->where('centre_id', $me->centre_id));
        }

        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }

        $this->appliquerPeriode($query, $data['periode'] ?? 'tout');

        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->whereLike('auteur', $term)
                    ->orWhereLike('detail', $term)
                    ->orWhereHas('contribuable', function (Builder $contribuable) use ($term): void {
                        $contribuable->whereLike('nif', $term)
                            ->orWhereLike('nom', $term)
                            ->orWhereHas('centre', fn (Builder $centre) => $centre->whereLike('nom', $term));
                    });
            });
        }

        $page = $query->paginate(20)->through(fn (DossierHistorique $historique) => [
            'id' => $historique->id,
            'alerte_id' => $historique->alerte_id,
            'action' => $historique->action,
            'action_libelle' => self::ACTIONS[$historique->action] ?? $historique->action,
            'auteur' => $historique->auteur,
            'detail' => $historique->detail,
            'statut_avant' => $historique->statut_avant,
            'statut_avant_libelle' => self::STATUTS[$historique->statut_avant] ?? $historique->statut_avant,
            'statut_apres' => $historique->statut_apres,
            'statut_apres_libelle' => self::STATUTS[$historique->statut_apres] ?? $historique->statut_apres,
            'cree_le' => $historique->created_at?->toIso8601String(),
            'contribuable' => [
                'id' => $historique->contribuable?->id,
                'nom' => $historique->contribuable?->nom,
                'nif' => $historique->contribuable?->nif,
                'centre' => $historique->contribuable?->centre?->nom,
            ],
        ]);

        return response()->json([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'actions' => collect(self::ACTIONS)
                ->map(fn (string $libelle, string $valeur) => ['value' => $valeur, 'label' => $libelle])
                ->values(),
        ]);
    }

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
