<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\Assujettissement;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Services\AnomalieAnalyseur;
use App\Services\DefaillanceDetector;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiche d'un contribuable : identité et état, obligations, alertes, et historique de ses déclarations
 * (12 derniers mois pour les impôts mensuels comme la TVA, 5 derniers exercices pour les impôts annuels comme l'IR).
 *   agent / admin        → uniquement les contribuables de LEUR centre ;
 *   central / superadmin → tous.
 */
class ContribuableController extends Controller
{
    private const MOIS_AFFICHES = 12;
    private const ANNEES_AFFICHEES = 5;

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
        $aujourdhui = AnomalieAnalyseur::aujourdhui();
        $jour = $aujourdhui->format('Y-m-d');

        // Alertes (toutes, de la plus récente à la plus ancienne)
        $alertes = $contribuable->alertes()->with('traitePar')->orderByDesc('annee_fiscale')->orderByDesc('mois')->orderByDesc('id')->get();

        // Chiffres clés
        $agg = $contribuable->declarations()
            ->selectRaw('count(*) as total, min(annee_fiscale) as premiere, max(annee_fiscale) as derniere')
            ->first();
        $premieres = $contribuable->declarations()
            ->selectRaw('type_impot, min(annee_fiscale * 100 + mois) as premiere')
            ->groupBy('type_impot')
            ->pluck('premiere', 'type_impot');

        // Obligations (assujettissements) et fin d'activité
        $assujs = $contribuable->assujettissements()->orderBy('type_impot')->orderByDesc('annee_fiscale')->get();
        $finActivite = collect([$contribuable->date_cessation, $contribuable->date_radiation])->filter()
            ->map(fn ($d) => $d->toDateString())->min();

        // Impôts connus pour ce contribuable, dans l'ordre de la config, séparés mensuels / annuels
        $periodicites = config('anomalies.defaillance.periodicite_defaut');
        $connus = $premieres->keys()->merge($alertes->pluck('type_impot'))->merge($assujs->pluck('type_impot'))->unique();
        $impots = collect(array_merge(array_keys($periodicites), $connus->all()))->unique()->filter(fn ($i) => $connus->contains($i))->values();
        $mensuels = $impots->filter(fn ($i) => ($periodicites[$i] ?? 'mensuelle') === 'mensuelle')->values();
        $annuels = $impots->filter(fn ($i) => ($periodicites[$i] ?? 'mensuelle') === 'annuelle')->values();

        // Fenêtres : 12 derniers mois (jusqu'au mois précédent ou à la dernière déclaration) et 5 derniers exercices
        $dernierMois = max((int) now()->subMonthNoOverflow()->format('Y') * 100 + (int) now()->subMonthNoOverflow()->format('n'), (int) ($premieres->isEmpty() ? 0 : $this->derniereMensuelle($contribuable, $mensuels->all())));
        $anneeFinM = intdiv($dernierMois, 100);
        $moisFinM = $dernierMois % 100;
        $periodes = [];
        for ($i = self::MOIS_AFFICHES - 1; $i >= 0; $i--) {
            $d = (new DateTimeImmutable(sprintf('%04d-%02d-01', $anneeFinM, $moisFinM)))->modify("-{$i} months");
            $periodes[] = ['annee' => (int) $d->format('Y'), 'mois' => (int) $d->format('n')];
        }
        $finAnnuelle = max((int) ($agg->derniere ?? 0), (int) $aujourdhui->format('Y') - 1);
        $annees = range($finAnnuelle - self::ANNEES_AFFICHEES + 1, $finAnnuelle);

        $debutM = $periodes[0]['annee'] * 100 + $periodes[0]['mois'];

        $declarations = $contribuable->declarations()
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('mois', '>', 0)->whereRaw('annee_fiscale * 100 + mois >= ?', [$debutM]))
                ->orWhere(fn ($a) => $a->where('mois', 0)->where('annee_fiscale', '>=', $annees[0])))
            ->get();
        $declarationsParCle = [];
        foreach ($declarations as $d) {
            $declarationsParCle[$d->type_impot . '|' . $d->annee_fiscale . '|' . $d->mois] = $d;
        }

        // Dates limites connues (déclarations de tous les contribuables) pour les impôts et périodes affichés
        $echeances = [];
        if ($impots->isNotEmpty()) {
            $rows = Declaration::query()->whereIn('type_impot', $impots->all())->whereNotNull('date_limite')
                ->where('annee_fiscale', '>=', min($annees[0], $periodes[0]['annee']))
                ->selectRaw('type_impot, annee_fiscale, mois, max(date_limite) as echeance')
                ->groupBy('type_impot', 'annee_fiscale', 'mois')->get();

            foreach ($rows as $r) {
                $echeances[$r->type_impot . '|' . $r->annee_fiscale . '|' . $r->mois] = substr((string) $r->echeance, 0, 10);
            }
        }

        // Obligation d'une période : d'après l'assujettissement, à défaut d'après la première déclaration
        $assujParImpotAnnee = [];
        foreach ($assujs as $a) {
            $assujParImpotAnnee[$a->type_impot][$a->annee_fiscale] = $a;
        }
        $obligee = function (string $impot, int $annee, int $mois) use ($assujParImpotAnnee, $premieres, $finActivite, $periodicites): bool {
            if (isset($assujParImpotAnnee[$impot])) {
                $a = $assujParImpotAnnee[$impot][$annee] ?? null;
                if ($a === null || ! $a->actif || $a->exonere) {
                    return false;
                }
                $periodicite = [1 => 'mensuelle', 5 => 'annuelle'][$a->periodicite] ?? null;

                return $periodicite !== null && in_array(
                    $mois,
                    DefaillanceDetector::periodesObligees($annee, $periodicite, $a->date_debut?->toDateString(), $a->date_fin?->toDateString(), $finActivite, null),
                    true
                );
            }

            $periodicite = $periodicites[$impot] ?? 'mensuelle';
            $premiere = $premieres->get($impot);

            return $premiere !== null && in_array(
                $mois,
                DefaillanceDetector::periodesObligees($annee, $periodicite, null, null, $finActivite, (int) $premiere),
                true
            );
        };

        $cellule = function (string $impot, int $annee, int $mois) use ($declarationsParCle, $echeances, $jour, $obligee): array {
            $d = $declarationsParCle[$impot . '|' . $annee . '|' . $mois] ?? null;
            $limite = $d?->date_limite?->toDateString() ?? ($echeances[$impot . '|' . $annee . '|' . $mois] ?? null);

            return [
                'annee' => $annee,
                'mois' => $mois,
                'declare' => $d !== null,
                'chiffre_affaires' => $d && $d->chiffre_affaires !== null ? (float) $d->chiffre_affaires : null,
                'date_limite' => $limite,
                'date_depot' => $d?->date_depot?->toDateString(),
                'echue' => $limite !== null && $limite < $jour,
                'obligee' => $obligee($impot, $annee, $mois),
            ];
        };

        $lignesMensuelles = $mensuels->map(fn (string $impot) => [
            'type_impot' => $impot,
            'cellules' => array_map(fn (array $p) => $cellule($impot, $p['annee'], $p['mois']), $periodes),
        ])->all();
        $lignesAnnuelles = $annuels->map(fn (string $impot) => [
            'type_impot' => $impot,
            'cellules' => array_map(fn (int $a) => $cellule($impot, $a, 0), $annees),
        ])->all();

        return response()->json([
            'contribuable' => [
                'id' => $contribuable->id,
                'nif' => $contribuable->nif,
                'nom' => $contribuable->nom,
                'centre' => $contribuable->centre?->nom,
                'etat' => $contribuable->etat,
                'etat_libelle' => $contribuable->etat !== null ? (Contribuable::ETATS[$contribuable->etat] ?? null) : null,
                'date_cessation' => $contribuable->date_cessation?->toDateString(),
                'date_radiation' => $contribuable->date_radiation?->toDateString(),
                'date_blocage' => $contribuable->date_blocage?->toDateString(),
            ],
            'stats' => [
                'nb_declarations' => (int) ($agg->total ?? 0),
                'premiere_annee' => $agg->premiere !== null ? (int) $agg->premiere : null,
                'derniere_annee' => $agg->derniere !== null ? (int) $agg->derniere : null,
            ],
            'obligations' => $assujs->filter(fn (Assujettissement $a) => $a->annee_fiscale >= (int) $aujourdhui->format('Y') - 1)->map(fn (Assujettissement $a) => [
                'type_impot' => $a->type_impot,
                'annee_fiscale' => $a->annee_fiscale,
                'periodicite' => Assujettissement::PERIODICITES[$a->periodicite] ?? (string) $a->periodicite,
                'actif' => $a->actif,
                'exonere' => $a->exonere,
            ])->values(),
            'alertes' => $alertes->map(fn (Alerte $a) => [
                'id' => $a->id,
                'regle' => $a->regle,
                'type_impot' => $a->type_impot,
                'annee_fiscale' => $a->annee_fiscale,
                'mois' => $a->mois,
                'date_limite' => $a->date_limite?->toDateString(),
                'periodes_manquees' => $a->periodes_manquees,
                'periodes_declarees' => $a->periodes_declarees,
                'niveau' => $a->niveau,
                'motif' => $a->motif,
                'details' => $a->details,
                'statut' => $a->statut,
                'commentaire' => $a->commentaire,
                'traite_par' => $a->traitePar?->nom,
                'traite_le' => $a->traite_le?->toIso8601String(),
            ])->values(),
            'historique' => [
                'mensuel' => ['periodes' => $periodes, 'impots' => $lignesMensuelles],
                'annuel' => ['annees' => $annees, 'impots' => $lignesAnnuelles],
            ],
        ]);
    }

    /** Dernier mois déclaré (année × 100 + mois) parmi les impôts mensuels du contribuable. */
    private function derniereMensuelle(Contribuable $contribuable, array $impotsMensuels): int
    {
        if ($impotsMensuels === []) {
            return 0;
        }

        return (int) $contribuable->declarations()->whereIn('type_impot', $impotsMensuels)->where('mois', '>', 0)
            ->selectRaw('max(annee_fiscale * 100 + mois) as dernier')->value('dernier');
    }
}
