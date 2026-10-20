<?php

namespace App\Services;

/**
 * Formules de calcul et interprétation de chaque règle, affichées dans « Règles de détection ».
 * Textes seulement (aucun accès base) ; les seuils d'interprétation reprennent les RÉGLAGES EN VIGUEUR.
 */
class FormulesRegles
{
    /**
     * @param array<string, mixed> $p réglages en vigueur de la règle
     * @return array{formules: array<int, array{label: string, expr: string}>, sources: array<int, string>, niveaux: array<int, array{couleur: string, texte: string}>}
     */
    public static function pour(string $code, array $p = []): array
    {
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

        return match ($code) {
            'defaillance' => [
                'formules' => [
                    ['label' => 'Déclaration annuelle (IR, IS)', 'expr' => 'non déposée à la date limite → score = ' . $n($p['score_annuel'] ?? 5) . ' / 5'],
                    ['label' => 'Déclarations mensuelles (TVA, IRSA)', 'expr' => 'score selon le nombre de mois non déposés dans l\'exercice : ' . self::bareme($p['bareme_mensuel'] ?? [])],
                ],
                'sources' => ['Déclarations déposées (date de dépôt) et obligations (assujettissements) de chaque contribuable'],
                'niveaux' => [
                    ['couleur' => 'vert', 'texte' => 'score inférieur à ' . $n($p['score_moyen'] ?? 4) . ' : risque faible, contribuable à relancer'],
                    ['couleur' => 'orange', 'texte' => 'score de ' . $n($p['score_moyen'] ?? 4) . ' à moins de ' . $n($p['score_eleve'] ?? 5) . ' : risque moyen, à surveiller de près'],
                    ['couleur' => 'rouge', 'texte' => 'score de ' . $n($p['score_eleve'] ?? 5) . ' ou plus : risque élevé, à contrôler'],
                ],
            ],
            'baisse_ca' => [
                'formules' => [
                    ['label' => 'Baisse du CA', 'expr' => 'baisse % = (CA de la période de référence − CA actuel) ÷ CA de la période de référence × 100'],
                    ['label' => 'Période de référence', 'expr' => 'TVA : même mois de l\'année précédente ; IR et IS : exercice précédent'],
                ],
                'sources' => ['CA : ligne 15 de l\'IR, ligne 170 de la TVA'],
                'niveaux' => [
                    ['couleur' => 'vert', 'texte' => 'baisse inférieure à ' . $n($p['seuil_pourcentage'] ?? 30) . ' % : pas d\'alerte'],
                    ['couleur' => 'faible', 'texte' => 'baisse d\'au moins ' . $n($p['seuil_pourcentage'] ?? 30) . ' % : alerte de risque faible'],
                    ['couleur' => 'orange', 'texte' => 'baisse d\'au moins ' . $n($p['seuil_moyen'] ?? 50) . ' % : risque moyen'],
                    ['couleur' => 'rouge', 'texte' => 'baisse d\'au moins ' . $n($p['seuil_eleve'] ?? 70) . ' % : risque élevé'],
                ],
            ],
            'r1' => [
                'formules' => [['label' => 'Score R1 (sur 10)', 'expr' => 'R1 = |CA IR − CA TVA| ÷ max(CA IR ; CA TVA) × 10']],
                'sources' => ['CA IR : ligne 15 de l\'IR', 'CA TVA : ligne 170 de la TVA, somme des mois de l\'exercice'],
                'niveaux' => self::niveauxStrict($n($p['seuil_moyen'] ?? 3), $n($p['seuil_eleve'] ?? 6), 'écart faible', 'écart important, risque moyen', 'écart élevé, à contrôler'),
            ],
            'r2' => [
                'formules' => [
                    ['label' => 'Série', 'expr' => 'nombre d\'exercices consécutifs avec un résultat IR négatif (< 0), sur les 3 derniers exercices'],
                    ['label' => 'Score R2 (sur 5)', 'expr' => '1 exercice → 1 ; 2 exercices → 2,5 ; 3 exercices → 5'],
                ],
                'sources' => ['Résultat : ligne 20 de l\'IR'],
                'niveaux' => self::niveauxLarge($n($p['seuil_moyen'] ?? 2.5), $n($p['seuil_eleve'] ?? 5), 'déficit isolé', 'déficit sur 2 exercices', 'déficit sur 3 exercices'),
            ],
            'r3' => [
                'formules' => [
                    ['label' => 'Série', 'expr' => 'nombre de mois consécutifs où le crédit de TVA est différent de 0, jusqu\'au dernier mois déclaré'],
                    ['label' => 'Score R3 (sur 5)', 'expr' => 'score = nombre de mois (5 mois ou plus → 5)'],
                ],
                'sources' => ['Crédit de TVA : ligne 400 de la TVA'],
                'niveaux' => self::niveauxLarge($n($p['seuil_moyen'] ?? 3), $n($p['seuil_eleve'] ?? 5), 'moins de ' . $n($p['seuil_moyen'] ?? 3) . ' mois', 'à surveiller', 'crédit de TVA anormalement persistant'),
            ],
            'r5' => [
                'formules' => [
                    ['label' => 'CA recoupé', 'expr' => 'CA RECOUP = prestations de service + produits locaux vendus + exportations + marchés publics'],
                    ['label' => 'Écarts de CA', 'expr' => 'Écart IR = |CA RECOUP − CA IR| ; Écart TVA = |CA RECOUP − CA TVA|'],
                    ['label' => 'TVA déductible', 'expr' => 'Locaux : |TVA_DED_ACHAT − (BL + ABL + IL)| ; Import : |TVA_DED_IMP − (BI + ABI + II)|'],
                    ['label' => 'CA non déclaré', 'expr' => 'chaque écart de TVA ÷ ' . $n(($p['taux_tva'] ?? 20)) . ' % × (1 + ' . $n($p['marge'] ?? 10) . ' % de marge), locaux + import'],
                    ['label' => 'Pourcentages', 'expr' => 'R5 IR = (Écart IR + CA non déclaré) × 100 ÷ CA IR ; R5 TVA = (Écart TVA + CA non déclaré) × 100 ÷ CA TVA'],
                    ['label' => 'Score R5 (sur 5)', 'expr' => 'points pour chaque % : > 10 → 1 ; > 20 → 2 ; > 30 → 3 ; > 40 → 4 ; > 50 → 5. Score = moyenne des points IR et TVA'],
                ],
                'sources' => ['Recoupement par NIF et par année', 'TVA déductible : lignes 300, 340, 315-316-320 (locaux) ; 305, 345, 330-335 (importés)'],
                'niveaux' => self::niveauxLarge($n($p['seuil_moyen'] ?? 3), $n($p['seuil_eleve'] ?? 5), 'dissimulation de CA ou majoration de stocks faible', 'dissimulation ou majoration de stocks importante', 'dissimulation ou majoration de stocks très élevée'),
            ],
            'r6' => [
                'formules' => [
                    ['label' => 'Montants', 'expr' => 'S1 = somme de la TVA des DAU modèle IM4 ; S2 = somme de la TVA déductible sur biens importés (ligne 305)'],
                    ['label' => 'Score R6 (sur 10)', 'expr' => 'R6 = |S1 − S2| ÷ max(S1 ; S2) × 10'],
                    ['label' => 'Cas limites', 'expr' => 'S1 = S2 = 0 → 0 ; S2 ≠ 0 et S1 = 0 → 10 (déduction possible à tort) ; S1 ≠ 0 et S2 = 0 → 10 (dissimulation possible)'],
                ],
                'sources' => ['DAU modèle IM4 : colonne TVA', 'TVA : ligne 305'],
                'niveaux' => self::niveauxStrict($n($p['seuil_moyen'] ?? 3), $n($p['seuil_eleve'] ?? 6), 'écart faible', 'écart important', 'écart élevé, à contrôler'),
            ],
            'r7' => [
                'formules' => [
                    ['label' => 'Montants', 'expr' => 'S1 = somme de la valeur CAF des DAU modèle EX1 ; S2 = somme du CA taxable relatif aux exportations (ligne 100)'],
                    ['label' => 'Score R7 (sur 10)', 'expr' => 'R7 = |S1 − S2| ÷ max(S1 ; S2) × 10'],
                    ['label' => 'Cas limites', 'expr' => 'S1 = S2 = 0 → 0 ; S2 ≠ 0 et S1 = 0 → 10 (blanchiment possible) ; S1 ≠ 0 et S2 = 0 → 10 (dissimulation possible)'],
                ],
                'sources' => ['DAU modèle EX1 : colonne VALEUR_CAF', 'TVA : ligne 100'],
                'niveaux' => self::niveauxStrict($n($p['seuil_moyen'] ?? 3), $n($p['seuil_eleve'] ?? 6), 'écart faible', 'écart important', 'écart élevé, à contrôler'),
            ],
            default => ['formules' => [], 'sources' => [], 'niveaux' => []],
        };
    }

    /** @param array<int, array<int, int>> $bareme */
    private static function bareme(array $bareme): string
    {
        $t = array_map(fn ($b) => "{$b[0]} à {$b[1]} mois → {$b[2]}", array_values($bareme));

        return $t === [] ? '—' : implode(' ; ', $t) . ' (sur 5)';
    }

    /** Seuils « strictement au-dessus » (R1, R6, R7). @return array<int, array{couleur: string, texte: string}> */
    private static function niveauxStrict(string $moyen, string $eleve, string $v, string $o, string $r): array
    {
        return [
            ['couleur' => 'vert', 'texte' => "score de 0 à {$moyen} : {$v}"],
            ['couleur' => 'orange', 'texte' => "score supérieur à {$moyen} et jusqu'à {$eleve} : {$o}"],
            ['couleur' => 'rouge', 'texte' => "score supérieur à {$eleve} : {$r}"],
        ];
    }

    /** Seuils « à partir de » (R2, R3, R5). @return array<int, array{couleur: string, texte: string}> */
    private static function niveauxLarge(string $moyen, string $eleve, string $v, string $o, string $r): array
    {
        return [
            ['couleur' => 'vert', 'texte' => "score inférieur à {$moyen} : {$v}"],
            ['couleur' => 'orange', 'texte' => "score de {$moyen} à moins de {$eleve} : {$o}"],
            ['couleur' => 'rouge', 'texte' => "score de {$eleve} ou plus : {$r}"],
        ];
    }
}
