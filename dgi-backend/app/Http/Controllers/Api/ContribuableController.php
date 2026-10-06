<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\Contribuable;
use App\Services\DefaillanceDetector;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiche d'un contribuable : identité, alertes et historique des déclarations des 12 derniers mois.
 *   agent / admin        → uniquement les contribuables de LEUR centre ;
 *   central / superadmin → tous.
 */
class ContribuableController extends Controller
{
    private const MOIS_AFFICHES = 12;

    public function show(Request $request, Contribuable $contribuable): JsonResponse
    {
        $me = $request->user();
        abort_if($me->role === null, 403, "Votre compte n'a pas de fonction attribuée.");

        if (in_array($me->role, [Role::Agent, Role::Admin], true)) {
            abort_if(
                $me->centre_id === null || $contribuable->centre_id !== $me->centre_id,
                403,
                "Ce contribuable n'appartient pas à votre centre."
            );
        }

        $contribuable->load('centre');

        // Alertes (toutes, de la plus récente à la plus ancienne)
        $alertes = $contribuable->alertes()->with('traitePar')->orderByDesc('periode')->orderByDesc('id')->get();

        // Chiffres clés sur les déclarations
        $agg = $contribuable->declarations()
            ->selectRaw('count(*) as total, min(periode) as premiere, max(periode) as derniere')
            ->first();
        $premieres = $contribuable->declarations()
            ->selectRaw('type_impot, min(periode) as premiere')
            ->groupBy('type_impot')
            ->pluck('premiere', 'type_impot');

        // Fenêtre de 12 mois qui se termine au mois de référence (ou à la dernière déclaration si plus récente)
        $fin = max(DefaillanceDetector::periodePrecedente(), (string) ($agg->derniere ?? ''));
        $finDate = Carbon::createFromFormat('!Y-m', $fin);
        $periodes = [];
        for ($i = self::MOIS_AFFICHES - 1; $i >= 0; $i--) {
            $periodes[] = $finDate->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        $declarations = $contribuable->declarations()
            ->whereBetween('periode', [$periodes[0], $periodes[self::MOIS_AFFICHES - 1]])
            ->get()
            ->groupBy('type_impot');

        // Une ligne par impôt connu pour ce contribuable (déclaré ou avec alerte), dans l'ordre de la config
        $connus = $premieres->keys()->merge($alertes->pluck('type_impot'))->unique();
        $ordre = array_merge(config('anomalies.defaillance.impots'), $connus->all());
        $impots = collect($ordre)->unique()->filter(fn ($i) => $connus->contains($i))->values();

        $lignes = $impots->map(function (string $impot) use ($declarations, $premieres, $periodes) {
            $parPeriode = $declarations->get($impot, collect())->keyBy('periode');

            return [
                'type_impot' => $impot,
                'premiere_periode' => $premieres->get($impot),
                'cellules' => array_map(function (string $periode) use ($parPeriode) {
                    $d = $parPeriode->get($periode);

                    return [
                        'periode' => $periode,
                        'declare' => $d !== null,
                        'montant' => $d ? (float) $d->montant : null,
                        'chiffre_affaires' => $d && $d->chiffre_affaires !== null ? (float) $d->chiffre_affaires : null,
                        'date_depot' => $d?->date_depot?->toDateString(),
                    ];
                }, $periodes),
            ];
        })->all();

        return response()->json([
            'contribuable' => [
                'id' => $contribuable->id,
                'nif' => $contribuable->nif,
                'nom' => $contribuable->nom,
                'centre' => $contribuable->centre?->nom,
            ],
            'stats' => [
                'nb_declarations' => (int) ($agg->total ?? 0),
                'premiere_periode' => $agg->premiere ?? null,
                'derniere_periode' => $agg->derniere ?? null,
            ],
            'alertes' => $alertes->map(fn (Alerte $a) => [
                'id' => $a->id,
                'regle' => $a->regle,
                'type_impot' => $a->type_impot,
                'periode' => $a->periode,
                'mois_manques' => $a->mois_manques,
                'serie_precedente' => $a->serie_precedente,
                'niveau' => $a->niveau,
                'motif' => $a->motif,
                'details' => $a->details,
                'statut' => $a->statut,
                'commentaire' => $a->commentaire,
                'traite_par' => $a->traitePar?->nom,
                'traite_le' => $a->traite_le?->toIso8601String(),
            ])->values(),
            'historique' => ['periodes' => $periodes, 'impots' => $lignes],
        ]);
    }
}
