<?php

namespace App\Services;

/**
 * Calcul des scores de risque R1, R2, R3, R5, R6, R7 (R4 = défaut de dépôt : DefaillanceDetector).
 * Source : document « AR_base_de_calcul_interpretation ». Fonctions PURES (aucun accès base) : testables seules.
 *
 * Sorties : un score et un niveau de risque (null = vert, « moyen » = orange, « eleve » = rouge).
 */
class CalculRisque
{
    public const CODES = ['r1', 'r2', 'r3', 'r5', 'r6', 'r7'];

    /** Score maximal de chaque critère. */
    public const SCORE_MAX = ['r1' => 10, 'r2' => 5, 'r3' => 5, 'r5' => 5, 'r6' => 10, 'r7' => 10];

    /** Écart relatif entre deux montants, sur 10 : |a - b| / max(a, b) * 10 (R1, R6, R7). Deux montants nuls : 0. */
    public static function ecartSur10(float $a, float $b): float
    {
        $max = max($a, $b);
        if ($max <= 0) {
            return 0.0;
        }

        return round(min(10.0, abs($a - $b) / $max * 10), 2);
    }

    /**
     * Niveau d'après le score : null (vert), « moyen » (orange), « eleve » (rouge).
     * $strict = true : orange si score > seuil moyen (R1, R6, R7 : « 3 < R <= 6 ») ; false : score >= seuil (R2, R3, R5).
     */
    public static function niveau(float $score, float $seuilMoyen, float $seuilEleve, bool $strict): ?string
    {
        $atteint = fn (float $seuil) => $strict ? $score > $seuil : $score >= $seuil;

        return $atteint($seuilEleve) ? 'eleve' : ($atteint($seuilMoyen) ? 'moyen' : null);
    }

    /** R2 : nombre d'exercices déficitaires consécutifs en remontant depuis $annee, sur $nb exercices au plus. */
    public static function serieDeficits(array $resultatsParAnnee, int $annee, int $nb = 3): int
    {
        $serie = 0;
        for ($a = $annee; $a > $annee - $nb; $a--) {
            $resultat = $resultatsParAnnee[$a] ?? null;
            if ($resultat === null || $resultat >= 0) {
                break; // exercice bénéficiaire ou sans déclaration : la série s'arrête
            }
            $serie++;
        }

        return $serie;
    }

    /** R2 : score sur 5 (3 exercices ou plus : 5 ; 2 : 2,5 ; 1 : 1). */
    public static function scoreR2(int $serie): float
    {
        return match (true) {
            $serie >= 3 => 5.0,
            $serie === 2 => 2.5,
            $serie === 1 => 1.0,
            default => 0.0,
        };
    }

    /**
     * R3 : nombre de mois consécutifs créditeurs de TVA, en remontant depuis ($annee, $mois).
     *
     * @param array<int, array<int, float|null>> $creditsParMois  année => mois => crédit de TVA
     */
    public static function serieCredits(array $creditsParMois, int $annee, int $mois): int
    {
        $serie = 0;
        $i = $annee * 12 + ($mois - 1);

        while (true) {
            $a = intdiv($i, 12);
            $m = $i % 12 + 1;
            $credit = $creditsParMois[$a][$m] ?? null;
            if ($credit === null || abs($credit) < 0.005) {
                break; // pas de crédit, ou mois non déclaré : la série s'arrête
            }
            $serie++;
            $i--;
        }

        return $serie;
    }

    /** R3 : score sur 5 = nombre de mois (5 mois ou plus : 5). */
    public static function scoreR3(int $serie): float
    {
        return (float) min(5, $serie);
    }

    /** R5 : points d'après le pourcentage d'écart (> 50 % : 5 ; > 40 : 4 ; > 30 : 3 ; > 20 : 2 ; > 10 : 1). null = CA nul avec écart : 5. */
    public static function pointsR5(?float $pourcentage): int
    {
        if ($pourcentage === null) {
            return 5;
        }

        return match (true) {
            $pourcentage > 50 => 5,
            $pourcentage > 40 => 4,
            $pourcentage > 30 => 3,
            $pourcentage > 20 => 2,
            $pourcentage > 10 => 1,
            default => 0,
        };
    }

    /**
     * R5 : discordance entre le CA déclaré (IR, TVA) et le recoupement.
     *
     * $d : ca_ir, ca_tva (null si non déclaré) ; recoupement (prestations_de_service, produits_locaux_vendus, exportations, marche_public,
     * tva_ded_achat, tva_ded_imp) ; TVA déclarée de l'année (tva_ded_bl, tva_ded_bi, déjà regroupées).
     * $taux : taux de TVA (0,20) ; $marge : marge supposée (0,10).
     *
     * @param array<string, float|null> $d
     * @return array<string, float|int|null>|null  null si ni CA IR ni CA TVA
     */
    public static function r5(array $d, float $taux = 0.20, float $marge = 0.10): ?array
    {
        $caIr = $d['ca_ir'] ?? null;
        $caTva = $d['ca_tva'] ?? null;
        if ($caIr === null && $caTva === null) {
            return null;
        }

        $caRecoup = (float) ($d['prestations_de_service'] ?? 0) + (float) ($d['produits_locaux_vendus'] ?? 0)
            + (float) ($d['exportations'] ?? 0) + (float) ($d['marche_public'] ?? 0);

        $csp2Loc = abs((float) ($d['tva_ded_achat'] ?? 0) - (float) ($d['tva_ded_bl'] ?? 0));
        $csp2Imp = abs((float) ($d['tva_ded_imp'] ?? 0) - (float) ($d['tva_ded_bi'] ?? 0));

        // CA non déclaré = écart de TVA déductible ramené à une base (÷ taux), majoré de la marge
        $nonDecLoc = self::caNonDeclare($csp2Loc, $taux, $marge);
        $nonDecImp = self::caNonDeclare($csp2Imp, $taux, $marge);
        $ecartCsp2 = $nonDecLoc + $nonDecImp;

        $r = ['ca_recoup' => $caRecoup, 'csp2_locaux' => $csp2Loc, 'csp2_import' => $csp2Imp, 'ca_non_declare' => round($ecartCsp2, 2),
            'r5_ir' => null, 'r5_tva' => null, 'points_ir' => null, 'points_tva' => null];

        $points = [];
        foreach (['ir' => $caIr, 'tva' => $caTva] as $cle => $ca) {
            if ($ca === null) {
                continue;
            }
            $ecart = abs($caRecoup - $ca) + $ecartCsp2;
            $pct = $ca > 0 ? round($ecart * 100 / $ca, 2) : ($ecart > 0 ? null : 0.0);
            $r["r5_{$cle}"] = $pct;
            $r["points_{$cle}"] = self::pointsR5($pct);
            $points[] = $r["points_{$cle}"];
        }

        $r['score'] = round(array_sum($points) / count($points), 2);

        return $r;
    }

    /**
     * CA non déclaré d'après un écart de TVA déductible : (écart ÷ taux) × (1 + marge).
     * NB : le document écrit « (CSP2 + 1/taux) × (1 + marge) » ; le « + » est lu comme une multiplication
     * (écart de TVA ÷ 20 % = écart d'achats), sinon le résultat serait quasi indépendant de l'écart.
     */
    public static function caNonDeclare(float $ecartTva, float $taux, float $marge): float
    {
        return $taux > 0 ? $ecartTva / $taux * (1 + $marge) : 0.0;
    }
}
