<?php

namespace App\Services;

use App\Models\Alerte;
use App\Models\BaseRisque;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Détection des critères de risque R1, R2, R3, R5, R6 et R7 à partir de la base d'analyse risque (table base_risque).
 * R4 (défaut de dépôt) reste détecté par DefaillanceDetector.
 *
 * Une alerte est créée par contribuable, critère et exercice dès que le niveau est ORANGE (« moyen ») ou ROUGE (« élevé ») ;
 * le vert n'en crée pas. Une alerte « à traiter » dont le risque a disparu devient « régularisée ».
 * Seuils, fenêtre d'analyse, taux de TVA et marge : « Règles de détection » dans l'application (défauts : config/risque.php).
 */
class DetecteurRisque
{
    private const NOMS_MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** @return array{analyses: int, crees: int, mis_a_jour: int, regularisees: int, par_regle: array<string, int>, inactives: array<int, string>} */
    public function analyser(DateTimeInterface $date, ?int $centreId = null): array
    {
        $stats = ['analyses' => 0, 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0, 'par_regle' => [], 'inactives' => []];

        $cfgs = [];
        foreach (CalculRisque::CODES as $code) {
            if (ReglesDetection::actif($code)) {
                $cfgs[$code] = ReglesDetection::parametres($code);
            } else {
                $stats['inactives'][] = $code;
            }
        }
        if ($cfgs === []) {
            return $stats;
        }

        // [AJOUT] Un exercice n'est jugé qu'une fois CLOS : dernière échéance de déclaration du calendrier
        // fiscal (IS, IR, TVA de décembre) + 48 h. Avant, les déclarations peuvent encore être modifiées.
        $anneeMax = CalendrierFiscal::dernierExerciceClos($date);
        if ($anneeMax === null) {
            return $stats; // aucun exercice ne peut être clos (calendrier fiscal inutilisable)
        }
        $anneeMin = $anneeMax - (int) max(array_map(fn ($c) => (int) ($c['fenetre_exercices'] ?? 1), $cfgs));

        // Années pour lesquelles des DAU ont été chargés : sans cela, « aucun DAU » serait pris pour « aucune importation »
        $anneesDau = DB::table('base_risque')->where('source', BaseRisque::DAU)->distinct()->pluck('annee_fiscale')->map(fn ($a) => (int) $a)->all();

        $trouvees = [];
        $evalues = []; // contribuables dont les données ont été examinées

        foreach ($this->groupes($anneeMin - 2, $anneeMax, $centreId) as $contribuableId => $g) {
            $evalues[$contribuableId] = true;
            $stats['analyses']++;
            foreach (self::evaluer($g, $cfgs, $anneeMin, $anneeMax, $anneesDau) as $t) {
                $t['contribuable_id'] = $contribuableId;
                $trouvees[] = $t;
            }
        }

        DB::transaction(function () use ($trouvees, $evalues, $anneeMin, $anneeMax, &$stats, $cfgs) {
            $actuelles = [];

            foreach ($trouvees as $t) {
                $alerte = Alerte::firstOrNew([
                    'contribuable_id' => $t['contribuable_id'],
                    'regle' => $t['regle'],
                    'type_impot' => $t['type_impot'],
                    'annee_fiscale' => $t['annee_fiscale'],
                    'mois' => $t['mois'],
                ]);
                $existait = $alerte->exists;

                $alerte->fill([
                    'periodes_manquees' => 0,
                    'periodes_declarees' => 0,
                    'niveau' => $t['niveau'],
                    'motif' => $t['motif'],
                    'details' => $t['details'],
                ]);

                if (! $existait) {
                    $alerte->statut = Alerte::A_TRAITER;
                } elseif ($alerte->statut === Alerte::REGULARISEE) {
                    $alerte->statut = Alerte::A_TRAITER; // le risque est de nouveau constaté
                    $alerte->traite_par = null;
                    $alerte->traite_le = null;
                    $alerte->forceFill(['pris_par' => null, 'pris_le' => null]);
                }

                $alerte->save();
                $existait ? $stats['mis_a_jour']++ : $stats['crees']++;
                $stats['par_regle'][$t['regle']] = ($stats['par_regle'][$t['regle']] ?? 0) + 1;

                $actuelles[$t['contribuable_id'].'|'.$t['regle'].'|'.$t['type_impot'].'|'.$t['annee_fiscale'].'|'.$t['mois']] = true;
            }

            // Alertes ouvertes dont le risque a disparu → régularisées (seulement pour les contribuables examinés).
            // Les exercices non clos ne sont pas jugés : leurs alertes ne sont ni confirmées ni régularisées.
            $ouvertes = Alerte::query()
                ->whereIn('regle', array_keys($cfgs))
                ->where('statut', Alerte::A_TRAITER)
                ->whereBetween('annee_fiscale', [$anneeMin, $anneeMax])
                ->whereIn('contribuable_id', array_keys($evalues))
                ->get(['id', 'contribuable_id', 'regle', 'type_impot', 'annee_fiscale', 'mois']);

            foreach ($ouvertes as $a) {
                if (! isset($actuelles[$a->contribuable_id.'|'.$a->regle.'|'.$a->type_impot.'|'.$a->annee_fiscale.'|'.$a->mois])) {
                    $a->forceFill(['statut' => Alerte::REGULARISEE, 'pris_par' => null, 'pris_le' => null])->save();
                    $stats['regularisees']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Lit la base par contribuable (lignes triées) : source => année => ligne, et pour la TVA source => année => mois => ligne.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function groupes(int $anneeMin, int $anneeMax, ?int $centreId): \Generator
    {
        $query = BaseRisque::query()
            ->join('contribuables', 'contribuables.id', '=', 'base_risque.contribuable_id')
            ->whereBetween('base_risque.annee_fiscale', [$anneeMin, $anneeMax])
            ->select('base_risque.*')
            ->orderBy('base_risque.contribuable_id');

        if ($centreId !== null) {
            $query->where('contribuables.centre_id', $centreId);
        }

        $courant = null;
        $g = [];

        foreach ($query->cursor() as $row) {
            if ($courant !== null && (int) $row->contribuable_id !== $courant) {
                yield $courant => $g;
                $g = [];
            }
            $courant = (int) $row->contribuable_id;
            $valeurs = [];
            foreach (BaseRisque::COLONNES[$row->source] ?? [] as $c) {
                $valeurs[$c] = $row->{$c} !== null ? (float) $row->{$c} : null;
            }
            if ($row->source === BaseRisque::TVA) {
                $g['TVA'][(int) $row->annee_fiscale][(int) $row->mois] = $valeurs;
            } else {
                $g[$row->source][(int) $row->annee_fiscale] = $valeurs;
            }
        }

        if ($courant !== null) {
            yield $courant => $g;
        }
    }

    /**
     * Évalue les critères d'UN contribuable (aucun accès base : testable seul).
     *
     * @param  array<string, mixed>  $g  IR[annee] ; TVA[annee][mois] ; RECOUPEMENT[annee] ; DAU[annee] (valeurs par indicateur)
     * @param  array<string, array<string, mixed>>  $cfgs  réglages des critères ACTIFS (clé = code r1...)
     * @param  array<int, int>  $anneesDau  années pour lesquelles des DAU sont chargés
     * @return array<int, array<string, mixed>> regle, type_impot, annee_fiscale, mois, niveau, motif, details
     */
    public static function evaluer(array $g, array $cfgs, int $anneeMin, int $anneeMax, array $anneesDau = []): array
    {
        $res = [];
        $ir = $g['IR'] ?? [];
        $tva = $g['TVA'] ?? [];

        // R2 : un résultat par année
        $resultats = [];
        foreach ($ir as $a => $l) {
            if (($l['resultat'] ?? null) !== null) {
                $resultats[$a] = $l['resultat'];
            }
        }
        // R3 : crédit de TVA par année et par mois
        $credits = [];
        foreach ($tva as $a => $mois) {
            foreach ($mois as $m => $l) {
                if (($l['credit_tva'] ?? null) !== null) {
                    $credits[$a][$m] = $l['credit_tva'];
                }
            }
        }

        for ($annee = $anneeMax; $annee >= $anneeMin; $annee--) {
            $fenetre = fn (string $code) => isset($cfgs[$code]) && $annee >= $anneeMax - (int) ($cfgs[$code]['fenetre_exercices'] ?? 1);
            $caIr = $ir[$annee]['ca'] ?? null;
            $lignesTva = $tva[$annee] ?? [];
            $caTva = self::somme($lignesTva, 'ca');
            $somme = fn (string $c) => self::somme($lignesTva, $c) ?? 0.0;

            // R1 : écart entre CA IR et CA TVA
            if ($fenetre('r1') && $caIr !== null && $caTva !== null) {
                $score = CalculRisque::ecartSur10($caIr, $caTva);
                $niveau = CalculRisque::niveau($score, (float) $cfgs['r1']['seuil_moyen'], (float) $cfgs['r1']['seuil_eleve'], true);
                if ($niveau !== null) {
                    $res[] = self::alerte('r1', 'IR/TVA', $annee, $niveau, $score, [
                        'ca_ir' => $caIr, 'ca_tva' => $caTva, 'ecart_pct' => round($score * 10, 1),
                    ], sprintf(
                        "IR et TVA : le chiffre d'affaires de %d diffère de %s %% entre l'IR (%s Ar) et la TVA (%s Ar). Score R1 : %s/10.",
                        $annee, self::nb($score * 10, 1), self::nb($caIr), self::nb($caTva), self::nb($score, 1)
                    ));
                }
            }

            // R2 : déficit chronique
            if ($fenetre('r2') && isset($resultats[$annee])) {
                $serie = CalculRisque::serieDeficits($resultats, $annee, 3);
                $score = CalculRisque::scoreR2($serie);
                $niveau = CalculRisque::niveau($score, (float) $cfgs['r2']['seuil_moyen'], (float) $cfgs['r2']['seuil_eleve'], false);
                if ($niveau !== null) {
                    $res[] = self::alerte('r2', 'IR', $annee, $niveau, $score, [
                        'exercices_deficitaires' => $serie, 'resultat' => $resultats[$annee],
                    ], sprintf(
                        "IR : résultat déficitaire pour %d exercice%s consécutif%s jusqu'en %d (résultat %s Ar). Score R2 : %s/5.",
                        $serie, $serie > 1 ? 's' : '', $serie > 1 ? 's' : '', $annee, self::nb($resultats[$annee]), self::nb($score, 1)
                    ));
                }
            }

            // R3 : crédit de TVA permanent (série arrêtée au dernier mois déclaré de l'exercice)
            if ($fenetre('r3') && isset($credits[$annee])) {
                $dernierMois = max(array_keys($credits[$annee]));
                $serie = CalculRisque::serieCredits($credits, $annee, $dernierMois);
                $score = CalculRisque::scoreR3($serie);
                $niveau = CalculRisque::niveau($score, (float) $cfgs['r3']['seuil_moyen'], (float) $cfgs['r3']['seuil_eleve'], false);
                if ($niveau !== null) {
                    $res[] = self::alerte('r3', 'TVA', $annee, $niveau, $score, [
                        'mois_consecutifs' => $serie, 'dernier_mois' => $dernierMois, 'credit_tva' => $credits[$annee][$dernierMois],
                    ], sprintf(
                        'TVA : crédit de TVA depuis %d mois consécutifs, jusqu\'à %s %d (crédit de %s Ar). Score R3 : %s/5.',
                        $serie, self::NOMS_MOIS[$dernierMois - 1], $annee, self::nb($credits[$annee][$dernierMois]), self::nb($score, 1)
                    ));
                }
            }

            // R5 : discordance avec le recoupement
            if ($fenetre('r5') && isset($g['RECOUPEMENT'][$annee])) {
                $rc = $g['RECOUPEMENT'][$annee];
                $r = CalculRisque::r5([
                    'ca_ir' => $caIr, 'ca_tva' => $caTva,
                    'prestations_de_service' => $rc['prestations_de_service'] ?? null,
                    'produits_locaux_vendus' => $rc['produits_locaux_vendus'] ?? null,
                    'exportations' => $rc['exportations'] ?? null,
                    'marche_public' => $rc['marche_public'] ?? null,
                    'tva_ded_achat' => $rc['tva_ded_achat'] ?? null,
                    'tva_ded_imp' => $rc['tva_ded_imp'] ?? null,
                    'tva_ded_bl' => $somme('tva_deduc_bl') + $somme('tva_deduc_abl') + $somme('tva_deduc_il'),
                    'tva_ded_bi' => $somme('tva_deduc_bi') + $somme('tva_deduc_abi') + $somme('tva_deduc_ii'),
                ], (float) $cfgs['r5']['taux_tva'] / 100, (float) $cfgs['r5']['marge'] / 100);

                if ($r !== null) {
                    $niveau = CalculRisque::niveau($r['score'], (float) $cfgs['r5']['seuil_moyen'], (float) $cfgs['r5']['seuil_eleve'], false);
                    if ($niveau !== null) {
                        $res[] = self::alerte('r5', 'IR/TVA', $annee, $niveau, $r['score'], [
                            'ca_recoupement' => $r['ca_recoup'], 'ca_ir' => $caIr, 'ca_tva' => $caTva,
                            'ca_non_declare' => $r['ca_non_declare'], 'ecart_ir_pct' => $r['r5_ir'], 'ecart_tva_pct' => $r['r5_tva'],
                        ], sprintf(
                            "Le chiffre d'affaires déclaré en %d (IR %s Ar, TVA %s Ar) s'écarte du recoupement (%s Ar)%s. Score R5 : %s/5.",
                            $annee, $caIr !== null ? self::nb($caIr) : 'non déclaré', $caTva !== null ? self::nb($caTva) : 'non déclaré',
                            self::nb($r['ca_recoup']),
                            $r['ca_non_declare'] > 0 ? ', avec '.self::nb($r['ca_non_declare']).' Ar de CA non déclaré estimé d\'après la TVA déductible' : '',
                            self::nb($r['score'], 1)
                        ));
                    }
                }
            }

            // R6 : importation (TVA des DAU IM4 et TVA déductible sur biens importés)
            if ($fenetre('r6') && in_array($annee, $anneesDau, true)) {
                $s1 = (float) ($g['DAU'][$annee]['dau_tva_im4'] ?? 0);
                $s2 = $somme('tva_deduc_bi');
                $score = CalculRisque::ecartSur10($s1, $s2);
                $niveau = CalculRisque::niveau($score, (float) $cfgs['r6']['seuil_moyen'], (float) $cfgs['r6']['seuil_eleve'], true);
                if ($niveau !== null) {
                    $cause = $s1 == 0.0 ? 'TVA déductible sans DAU d\'importation : déduction possiblement à tort'
                        : ($s2 == 0.0 ? 'importations sans TVA déductible déclarée : dissimulation possible de stock ou de CA' : 'écart entre DAU et déclaration');
                    $res[] = self::alerte('r6', 'TVA', $annee, $niveau, $score, [
                        'tva_dau_im4' => $s1, 'tva_deduc_biens_importes' => $s2,
                    ], sprintf(
                        'Importations %d : TVA des DAU IM4 de %s Ar contre %s Ar de TVA déductible sur biens importés (%s). Score R6 : %s/10.',
                        $annee, self::nb($s1), self::nb($s2), $cause, self::nb($score, 1)
                    ));
                }
            }

            // R7 : exportation (valeur CAF des DAU EX1 et CA taxable à l'exportation)
            if ($fenetre('r7') && in_array($annee, $anneesDau, true)) {
                $s1 = (float) ($g['DAU'][$annee]['dau_caf_ex1'] ?? 0);
                $s2 = $somme('ca_taxable_export');
                $score = CalculRisque::ecartSur10($s1, $s2);
                $niveau = CalculRisque::niveau($score, (float) $cfgs['r7']['seuil_moyen'], (float) $cfgs['r7']['seuil_eleve'], true);
                if ($niveau !== null) {
                    $cause = $s1 == 0.0 ? 'CA export déclaré sans DAU d\'exportation : blanchiment d\'argent possible'
                        : ($s2 == 0.0 ? 'exportations sans CA export déclaré : dissimulation possible de stock ou de CA' : 'écart entre DAU et déclaration');
                    $res[] = self::alerte('r7', 'TVA', $annee, $niveau, $score, [
                        'valeur_caf_ex1' => $s1, 'ca_taxable_export' => $s2,
                    ], sprintf(
                        'Exportations %d : valeur CAF des DAU EX1 de %s Ar contre %s Ar de CA taxable à l\'exportation (%s). Score R7 : %s/10.',
                        $annee, self::nb($s1), self::nb($s2), $cause, self::nb($score, 1)
                    ));
                }
            }
        }

        return $res;
    }

    /** Somme d'un indicateur sur les mois d'une année ; null si aucune valeur. @param array<int, array<string, float|null>> $mois */
    private static function somme(array $mois, string $colonne): ?float
    {
        $total = null;
        foreach ($mois as $l) {
            if (($l[$colonne] ?? null) !== null) {
                $total = ($total ?? 0.0) + $l[$colonne];
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $indicateurs @return array<string, mixed> */
    private static function alerte(string $regle, string $impot, int $annee, string $niveau, float $score, array $indicateurs, string $motif): array
    {
        return [
            'regle' => $regle,
            'type_impot' => $impot,
            'annee_fiscale' => $annee,
            'mois' => 0,
            'niveau' => $niveau,
            'motif' => $motif,
            'details' => ['score_risque' => $score, 'score_max' => CalculRisque::SCORE_MAX[$regle], 'indicateurs' => $indicateurs],
        ];
    }

    private static function nb(float $v, int $dec = 0): string
    {
        return number_format($v, $dec, ',', ' ');
    }
}
