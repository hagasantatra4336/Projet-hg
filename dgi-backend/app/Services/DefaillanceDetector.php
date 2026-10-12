<?php

namespace App\Services;

use App\Models\Alerte;
use App\Models\Assujettissement;
use App\Models\Declaration;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Détection de la DÉFAILLANCE DE DÉPÔT (critère R4 de la DGI : « Défaut de dépôt de déclaration »).
 *
 * Pour chaque contribuable, impôt et exercice (année fiscale) récent :
 *   1. on détermine les périodes à déclarer (obligations) :
 *        - d'après les ASSUJETTISSEMENTS du contribuable (actif, non exonéré) : 12 mois pour une périodicité
 *          mensuelle (TVA, IRSA), 1 déclaration pour une périodicité annuelle (IR, IS) ;
 *        - à défaut d'assujettissement connu pour cet impôt : déduites de sa première déclaration ;
 *        - jamais après la date de cessation ou de radiation du contribuable ;
 *   2. on ne retient que les périodes dont la DATE LIMITE est dépassée (la date limite d'une période est celle
 *      indiquée dans les déclarations déposées pour cet impôt et cette période : la plus tardive) ;
 *   3. celles qui n'ont pas de déclaration sont des « défauts de dépôt ».
 * Une alerte est créée par (contribuable, impôt, exercice) avec le score R4 (sur 5) :
 *   déclaration annuelle non déposée = 5 ; mois de TVA non déposés dans l'exercice : 1-4 → 2, 5-9 → 3, 10-12 → 4.
 *   Score 5 → risque élevé (rouge), 4 → moyen (orange), 2-3 → faible (vert). Seuils : config/anomalies.php.
 */
class DefaillanceDetector
{
    /**
     * @return array{date: string, analyses: int, crees: int, mis_a_jour: int, regularisees: int}
     */
    public function analyser(DateTimeInterface $date, ?int $centreId = null): array
    {
        $cfg = config('anomalies.defaillance');
        $aujourdhui = $date->format('Y-m-d');
        $anneeMin = (int) $date->format('Y') - (int) $cfg['fenetre_exercices'];

        // Contribuables concernés (filtre par centre pour l'admin)
        $contribuables = [];
        $q = DB::table('contribuables')->select('id', 'date_cessation', 'date_radiation');
        if ($centreId !== null) {
            $q->where('centre_id', $centreId);
        }
        foreach ($q->cursor() as $c) {
            $fins = array_filter([$c->date_cessation, $c->date_radiation]);
            $contribuables[(int) $c->id] = ['fin' => $fins === [] ? null : substr((string) min($fins), 0, 10)];
        }

        // Obligations connues (toutes années : sert aussi à savoir si l'impôt est suivi par un assujettissement)
        $assujs = [];
        foreach (Assujettissement::query()->whereIn('type_impot', $cfg['impots'])->cursor() as $a) {
            if (! isset($contribuables[$a->contribuable_id])) {
                continue;
            }
            $assujs[] = [
                'contribuable_id' => (int) $a->contribuable_id,
                'type_impot' => $a->type_impot,
                'annee_fiscale' => (int) $a->annee_fiscale,
                'periodicite' => (int) $a->periodicite,
                'date_debut' => $a->date_debut?->toDateString(),
                'date_fin' => $a->date_fin?->toDateString(),
                'actif' => (bool) $a->actif,
                'exonere' => (bool) $a->exonere,
            ];
        }

        // Déclarations déposées dans la fenêtre analysée + première déclaration de chaque couple (repli)
        $declares = [];
        $premieres = [];
        $rows = Declaration::query()->whereIn('type_impot', $cfg['impots'])
            ->selectRaw('contribuable_id, type_impot, min(annee_fiscale * 100 + mois) as premiere')
            ->groupBy('contribuable_id', 'type_impot')->cursor();
        foreach ($rows as $r) {
            if (isset($contribuables[$r->contribuable_id])) {
                $premieres[(int) $r->contribuable_id][$r->type_impot] = (int) $r->premiere;
            }
        }
        foreach (Declaration::query()->whereIn('type_impot', $cfg['impots'])->where('annee_fiscale', '>=', $anneeMin)
            ->select('contribuable_id', 'type_impot', 'annee_fiscale', 'mois')->cursor() as $d) {
            if (isset($contribuables[$d->contribuable_id])) {
                $declares[(int) $d->contribuable_id][$d->type_impot][(int) $d->annee_fiscale][(int) $d->mois] = true;
            }
        }

        // Dates limites connues : [impôt][année][mois] = date (la plus tardive indiquée)
        $echeances = [];
        $rows = Declaration::query()->whereIn('type_impot', $cfg['impots'])->whereNotNull('date_limite')
            ->where('annee_fiscale', '>=', $anneeMin)
            ->selectRaw('type_impot, annee_fiscale, mois, max(date_limite) as echeance')
            ->groupBy('type_impot', 'annee_fiscale', 'mois')->get();
        foreach ($rows as $r) {
            $echeances[$r->type_impot][(int) $r->annee_fiscale][(int) $r->mois] = substr((string) $r->echeance, 0, 10);
        }

        $trouvees = self::detecter($assujs, $declares, $premieres, $echeances, $contribuables, $aujourdhui, $cfg);

        // Couples (contribuable, impôt) examinés
        $paires = [];
        foreach ($assujs as $a) {
            $paires[$a['contribuable_id'] . '|' . $a['type_impot']] = true;
        }
        foreach ($premieres as $cid => $impots) {
            foreach (array_keys($impots) as $impot) {
                $paires[$cid . '|' . $impot] = true;
            }
        }

        $stats = ['date' => $aujourdhui, 'analyses' => count($paires), 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0];

        // Écriture : création / mise à jour des alertes, puis régularisation de celles qui ne tiennent plus
        DB::transaction(function () use ($trouvees, $anneeMin, $centreId, &$stats) {
            $actuelles = [];

            foreach ($trouvees as $t) {
                $alerte = Alerte::firstOrNew([
                    'contribuable_id' => $t['contribuable_id'],
                    'regle' => Alerte::REGLE_DEFAILLANCE,
                    'type_impot' => $t['type_impot'],
                    'annee_fiscale' => $t['annee_fiscale'],
                    'mois' => 0,
                ]);

                $existait = $alerte->exists;

                $alerte->fill([
                    'periodes_manquees' => $t['periodes_manquees'],
                    'periodes_declarees' => $t['periodes_declarees'],
                    'date_limite' => $t['date_limite'],
                    'niveau' => $t['niveau'],
                    'motif' => $t['motif'],
                    'details' => $t['details'],
                ]);

                if (! $existait) {
                    $alerte->statut = Alerte::A_TRAITER;
                } elseif ($alerte->statut === Alerte::REGULARISEE) {
                    // Les données montrent à nouveau la défaillance : on rouvre l'alerte.
                    $alerte->statut = Alerte::A_TRAITER;
                    $alerte->traite_par = null;
                    $alerte->traite_le = null;
                }

                $alerte->save();
                $existait ? $stats['mis_a_jour']++ : $stats['crees']++;

                $actuelles[$t['contribuable_id'] . '|' . $t['type_impot'] . '|' . $t['annee_fiscale']] = true;
            }

            // Alertes encore « à traiter » dont les déclarations ont depuis été déposées → régularisées automatiquement
            $ouvertes = Alerte::query()
                ->where('regle', Alerte::REGLE_DEFAILLANCE)
                ->where('statut', Alerte::A_TRAITER)
                ->where('annee_fiscale', '>=', $anneeMin)
                ->when($centreId !== null, fn ($q) => $q->whereHas(
                    'contribuable',
                    fn ($c) => $c->where('centre_id', $centreId)
                ))
                ->get(['id', 'contribuable_id', 'type_impot', 'annee_fiscale']);

            foreach ($ouvertes as $a) {
                if (! isset($actuelles[$a->contribuable_id . '|' . $a->type_impot . '|' . $a->annee_fiscale])) {
                    $a->update(['statut' => Alerte::REGULARISEE]);
                    $stats['regularisees']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Cœur de la détection, sur des tableaux simples (aucun accès base) : testable seul.
     *
     * @param array<int, array<string, mixed>> $assujs         tous les assujettissements (contribuable_id, type_impot,
     *                                                         annee_fiscale, periodicite, date_debut, date_fin, actif, exonere)
     * @param array<int, array<string, array<int, array<int, bool>>>> $declares  [contribuable][impôt][année][mois] = true
     * @param array<int, array<string, int>> $premieres        [contribuable][impôt] = année × 100 + mois de la première déclaration
     * @param array<string, array<int, array<int, string>>> $echeances  [impôt][année][mois] = date limite « AAAA-MM-JJ »
     * @param array<int, array{fin: string|null}> $contribuables  date de cessation ou de radiation (la plus ancienne)
     * @param array<string, mixed> $cfg
     * @return array<int, array<string, mixed>>
     */
    public static function detecter(
        array $assujs,
        array $declares,
        array $premieres,
        array $echeances,
        array $contribuables,
        string $aujourdhui,
        array $cfg
    ): array {
        $anneeCourante = (int) substr($aujourdhui, 0, 4);
        $annees = range($anneeCourante - (int) $cfg['fenetre_exercices'], $anneeCourante);

        // Assujettissements par couple (contribuable, impôt) puis par année
        $parPaire = [];
        foreach ($assujs as $a) {
            $parPaire[$a['contribuable_id'] . '|' . $a['type_impot']][$a['annee_fiscale']] = $a;
        }

        // Couples à examiner : ceux qui ont un assujettissement + ceux qui ont déjà déclaré sans assujettissement (repli)
        $paires = array_keys($parPaire);
        foreach ($premieres as $cid => $impots) {
            foreach (array_keys($impots) as $impot) {
                if (! isset($parPaire[$cid . '|' . $impot])) {
                    $paires[] = $cid . '|' . $impot;
                }
            }
        }

        $trouvees = [];

        foreach ($paires as $cle) {
            [$cid, $impot] = explode('|', $cle);
            $cid = (int) $cid;
            $fin = $contribuables[$cid]['fin'] ?? null;

            foreach ($annees as $annee) {
                if (isset($parPaire[$cle])) {
                    // Obligations connues : seules comptent les années avec un assujettissement actif et non exonéré
                    $a = $parPaire[$cle][$annee] ?? null;
                    if ($a === null || ! $a['actif'] || $a['exonere']) {
                        continue;
                    }
                    $periodicite = [1 => 'mensuelle', 5 => 'annuelle'][$a['periodicite']] ?? null; // autres périodicités non traitées
                    if ($periodicite === null) {
                        continue;
                    }
                    $obligees = self::periodesObligees($annee, $periodicite, $a['date_debut'], $a['date_fin'], $fin, null);
                    $origine = 'assujettissement';
                } else {
                    // Repli : obligation déduite de la première déclaration
                    $periodicite = $cfg['periodicite_defaut'][$impot] ?? null;
                    if ($periodicite === null) {
                        continue;
                    }
                    $obligees = self::periodesObligees($annee, $periodicite, null, null, $fin, $premieres[$cid][$impot]);
                    $origine = 'historique';
                }

                $deposees = array_keys($declares[$cid][$impot][$annee] ?? []);

                $trouvee = self::evaluer($cid, $impot, $annee, $periodicite, $obligees, $deposees, $echeances[$impot][$annee] ?? [], $aujourdhui, $cfg);

                if ($trouvee !== null) {
                    $trouvee['details']['origine_obligation'] = $origine;
                    $trouvees[] = $trouvee;
                }
            }
        }

        return $trouvees;
    }

    /**
     * Périodes à déclarer pour un exercice : mois 1 à 12 (mensuelle) ou [0] (annuelle).
     *
     * @param string|null $debut       début de l'assujettissement
     * @param string|null $fin         fin de l'assujettissement
     * @param string|null $finActivite date de cessation ou de radiation : aucune obligation après
     * @param int|null    $premiere    année × 100 + mois de la première déclaration (repli) : aucune obligation avant
     * @return array<int, int>
     */
    public static function periodesObligees(int $annee, string $periodicite, ?string $debut, ?string $fin, ?string $finActivite, ?int $premiere): array
    {
        $periodes = $periodicite === 'annuelle' ? [0] : range(1, 12);
        $obligees = [];

        foreach ($periodes as $mois) {
            $du = $mois === 0 ? sprintf('%04d-01-01', $annee) : sprintf('%04d-%02d-01', $annee, $mois);
            $au = $mois === 0 ? sprintf('%04d-12-31', $annee) : (new DateTimeImmutable($du))->format('Y-m-t');

            if ($debut !== null && $au < $debut) {
                continue; // la période finit avant l'assujettissement
            }
            if ($fin !== null && $du > $fin) {
                continue; // la période commence après la fin de l'assujettissement
            }
            if ($finActivite !== null && $du > $finActivite) {
                continue; // cessation ou radiation : plus d'obligation
            }
            if ($premiere !== null && $annee * 100 + $mois < $premiere) {
                continue; // avant la première déclaration connue
            }

            $obligees[] = $mois;
        }

        return $obligees;
    }

    /**
     * Évalue un exercice : périodes à déclarer, dont la date limite est dépassée, sans déclaration.
     * Fonction pure (sans accès base).
     *
     * @param array<int, int>    $obligees  mois à déclarer (0 = déclaration annuelle)
     * @param array<int, int>    $deposees  mois déclarés
     * @param array<int, string> $echeancesAnnee  mois => date limite « AAAA-MM-JJ »
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>|null
     */
    public static function evaluer(
        int $contribuableId,
        string $impot,
        int $annee,
        string $periodicite,
        array $obligees,
        array $deposees,
        array $echeancesAnnee,
        string $aujourdhui,
        array $cfg
    ): ?array {
        // Périodes dont la date limite est dépassée (strictement avant aujourd'hui) ; sans date limite connue : on ne peut pas conclure
        $dues = array_values(array_filter(
            $obligees,
            fn (int $m) => isset($echeancesAnnee[$m]) && $echeancesAnnee[$m] < $aujourdhui
        ));

        $manquantes = array_values(array_diff($dues, $deposees));

        if ($manquantes === []) {
            return null;
        }

        sort($manquantes);
        $n = count($manquantes);

        // Score R4
        $score = 0;
        if ($periodicite === 'annuelle') {
            $score = (int) $cfg['score_annuel'];
        } else {
            foreach ($cfg['bareme_mensuel'] as [$min, $max, $points]) {
                if ($n >= $min && $n <= $max) {
                    $score = (int) $points;
                }
            }
        }

        $niveau = $score >= $cfg['score_eleve'] ? 'eleve' : ($score >= $cfg['score_moyen'] ? 'moyen' : 'faible');

        // Retard : depuis la plus ancienne date limite non respectée
        $limite = $echeancesAnnee[$manquantes[0]];
        $jours = (int) (new DateTimeImmutable($limite))->diff(new DateTimeImmutable($aujourdhui))->days;

        $texteRetard = sprintf(
            'Plus ancienne date limite dépassée : %s (%d jour%s de retard).',
            (new DateTimeImmutable($limite))->format('d/m/Y'),
            $jours,
            $jours > 1 ? 's' : ''
        );

        if ($periodicite === 'annuelle') {
            $motif = sprintf('%s : déclaration de l\'exercice %d non déposée. %s Score R4 : %d/5.', $impot, $annee, $texteRetard, $score);
        } else {
            $noms = array_map(fn (int $m) => self::NOMS_MOIS[$m - 1], $manquantes);
            $motif = sprintf(
                '%s : %d mois non déposé%s pour l\'exercice %d (%s) sur %d dus. %s Score R4 : %d/5.',
                $impot,
                $n,
                $n > 1 ? 's' : '',
                $annee,
                implode(', ', $noms),
                count($dues),
                $texteRetard,
                $score
            );
        }

        return [
            'contribuable_id' => $contribuableId,
            'type_impot' => $impot,
            'annee_fiscale' => $annee,
            'periodes_manquees' => $n,
            'periodes_declarees' => count(array_intersect($dues, $deposees)),
            'date_limite' => $limite,
            'niveau' => $niveau,
            'motif' => $motif,
            'details' => [
                'score_r4' => $score,
                'periodicite' => $periodicite,
                'mois_manquants' => $periodicite === 'annuelle' ? [] : $manquantes,
                'jours_retard' => $jours,
            ],
        ];
    }

    private const NOMS_MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
}
