<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\User;
use App\Services\DefaillanceDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Alertes de défaillance de déclaration.
 *
 *  - Consultation et traitement : tous les rôles.
 *      agent / admin        → uniquement les alertes de LEUR centre ;
 *      central / superadmin → tous les centres.
 *  - Lancer l'analyse : superadmin, central, admin (l'admin est limité à son centre).
 */
class DefaillanceController extends Controller
{
    private const PERIODE_REGEX = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'niveau' => ['nullable', Rule::in(Alerte::NIVEAUX)],
            'type_impot' => ['nullable', Rule::in(config('anomalies.defaillance.impots'))],
            'statut' => ['nullable', Rule::in([Alerte::A_TRAITER, Alerte::TRAITEE, Alerte::REGULARISEE])],
            'periode' => ['nullable', 'regex:' . self::PERIODE_REGEX],
            'centre_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['niveau', 'type_impot', 'periode'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = $this->scopedQuery($request)->with(['contribuable.centre', 'traitePar']);

        foreach (['niveau', 'type_impot', 'statut', 'periode'] as $champ) {
            if (! empty($data[$champ])) {
                $query->where('alertes.' . $champ, $data[$champ]);
            }
        }

        if (! empty($data['search'])) {
            $term = '%' . trim($data['search']) . '%';
            $query->whereHas('contribuable', function ($q) use ($term) {
                // ILIKE : insensible à la casse (PostgreSQL)
                $q->where('nom', 'ilike', $term)->orWhere('nif', 'ilike', $term);
            });
        }

        // Tri par défaut : les plus risquées d'abord, puis les plus récentes.
        $dir = ($data['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = $data['sort'] ?? 'niveau';

        if ($sort === 'niveau') {
            $query->orderByRaw("CASE alertes.niveau WHEN 'eleve' THEN 3 WHEN 'moyen' THEN 2 ELSE 1 END {$dir}")
                ->orderBy('alertes.periode', 'desc');
        } else {
            $query->orderBy('alertes.' . $sort, $dir);
        }
        $query->orderBy('alertes.id', 'desc');

        $page = $query->paginate(10)->through(fn (Alerte $a) => $this->present($a));

        return response()->json($page);
    }

    /** Compteurs pour les cartes du haut de page (alertes à traiter par niveau + totaux). */
    public function summary(Request $request): JsonResponse
    {
        $rows = $this->scopedQuery($request)
            ->selectRaw('alertes.statut, alertes.niveau, count(*) as total')
            ->groupBy('alertes.statut', 'alertes.niveau')
            ->get();

        $summary = ['a_traiter' => 0, 'eleve' => 0, 'moyen' => 0, 'faible' => 0, 'traitees' => 0, 'regularisees' => 0];

        foreach ($rows as $r) {
            $n = (int) $r->total;

            if ($r->statut === Alerte::A_TRAITER) {
                $summary['a_traiter'] += $n;
                $summary[$r->niveau] += $n;
            } elseif ($r->statut === Alerte::TRAITEE) {
                $summary['traitees'] += $n;
            } else {
                $summary['regularisees'] += $n;
            }
        }

        return response()->json($summary + [
            'derniere_analyse' => Cache::get(DefaillanceDetector::CACHE_DERNIERE_ANALYSE),
        ]);
    }

    /** EXF-008 : marquer une alerte comme traitée (ou la rouvrir) avec un commentaire libre. */
    public function update(Request $request, Alerte $alerte): JsonResponse
    {
        $me = $request->user();
        $this->assertAccess($me, $alerte);

        $data = $request->validate([
            'statut' => ['sometimes', Rule::in([Alerte::A_TRAITER, Alerte::TRAITEE])],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('statut', $data) && $alerte->statut === Alerte::REGULARISEE) {
            return response()->json([
                'message' => 'Cette alerte est régularisée (le contribuable a déposé) : son statut ne peut pas être modifié.',
            ], 422);
        }

        if (array_key_exists('commentaire', $data)) {
            $alerte->commentaire = trim((string) $data['commentaire']) !== '' ? trim((string) $data['commentaire']) : null;
        }

        if (array_key_exists('statut', $data) && $data['statut'] !== $alerte->statut) {
            $alerte->statut = $data['statut'];
            $alerte->traite_par = $data['statut'] === Alerte::TRAITEE ? $me->id : null;
            $alerte->traite_le = $data['statut'] === Alerte::TRAITEE ? now() : null;
        }

        $alerte->save();

        return response()->json($this->present($alerte->load(['contribuable.centre', 'traitePar'])));
    }

    /** Lance le moteur de détection (superadmin, central, admin). */
    public function analyser(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'periode' => ['nullable', 'regex:' . self::PERIODE_REGEX],
        ]);

        $periode = $data['periode'] ?? DefaillanceDetector::periodePrecedente();

        // L'admin n'analyse que son propre centre.
        $centreId = null;
        if ($me->role === Role::Admin) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $centreId = $me->centre_id;
        }

        $stats = (new DefaillanceDetector())->analyser($periode, $centreId);

        return response()->json($stats + [
            'message' => sprintf(
                'Analyse terminée : %d nouvelle(s) alerte(s), %d mise(s) à jour, %d régularisée(s).',
                $stats['crees'],
                $stats['mis_a_jour'],
                $stats['regularisees']
            ),
        ]);
    }

    // ------------------------------------------------------------------

    /** Alertes visibles par l'utilisateur connecté (filtrage par centre selon son rôle). */
    private function scopedQuery(Request $request): Builder
    {
        $me = $request->user();
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        $query = Alerte::query()->where('alertes.regle', Alerte::REGLE_DEFAILLANCE);

        if (in_array($me->role, [Role::Agent, Role::Admin], true)) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $query->whereHas('contribuable', fn ($q) => $q->where('centre_id', $me->centre_id));
        } elseif ($request->filled('centre_id')) {
            $query->whereHas('contribuable', fn ($q) => $q->where('centre_id', (int) $request->input('centre_id')));
        }

        return $query;
    }

    private function assertAccess(User $me, Alerte $alerte): void
    {
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        if (in_array($me->role, [Role::Agent, Role::Admin], true)) {
            $alerte->loadMissing('contribuable');
            abort_if(
                $me->centre_id === null || $alerte->contribuable->centre_id !== $me->centre_id,
                403,
                "Cette alerte n'appartient pas à votre centre."
            );
        }
    }

    /** @return array<string, mixed> */
    private function present(Alerte $a): array
    {
        return [
            'id' => $a->id,
            'contribuable' => [
                'id' => $a->contribuable->id,
                'nif' => $a->contribuable->nif,
                'nom' => $a->contribuable->nom,
                'centre' => $a->contribuable->centre?->nom,
            ],
            'type_impot' => $a->type_impot,
            'periode' => $a->periode,
            'mois_manques' => $a->mois_manques,
            'serie_precedente' => $a->serie_precedente,
            'niveau' => $a->niveau,
            'motif' => $a->motif,
            'statut' => $a->statut,
            'commentaire' => $a->commentaire,
            'traite_par' => $a->traitePar?->nom,
            'traite_le' => $a->traite_le?->toIso8601String(),
        ];
    }
}
