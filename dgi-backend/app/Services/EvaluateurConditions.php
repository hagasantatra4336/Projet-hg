<?php

namespace App\Services;

/**
 * Évalue les conditions d'une règle personnalisée sur UNE déclaration. Fonctions pures (aucun accès base) : testable seul.
 *
 * Arbre de conditions (2 niveaux de groupes au plus, 8 conditions au plus) :
 *   {"operateur": "et"|"ou", "conditions": [ <condition> | <groupe>, ... ]}
 * Condition :
 *   {"indicateur": "chiffre_affaires", "mesure": "baisse_pct_n1", "comparateur": ">=", "valeur": 30, "indicateur_b": null}
 *
 * Mesures :
 *   valeur         valeur de l'indicateur dans la déclaration
 *   baisse_pct_n1  baisse en % par rapport à la même période de l'année précédente (positive si ça baisse)
 *   hausse_pct_n1  hausse en % par rapport à la même période de l'année précédente (négative si ça baisse)
 *   ecart_n1       valeur - valeur de la même période de l'année précédente
 *   ratio_pct      indicateur / indicateur_b × 100 (même déclaration)
 * Une mesure non calculable (valeur absente, référence absente ou nulle) rend la condition FAUSSE, sauf « vide ».
 */
final class EvaluateurConditions
{
    public const MESURES = ['valeur', 'baisse_pct_n1', 'hausse_pct_n1', 'ecart_n1', 'ratio_pct'];
    public const COMPARATEURS = ['>', '>=', '<', '<=', '=', '!=', 'vide', 'non_vide'];
    public const MAX_CONDITIONS = 8;
    public const MAX_PROFONDEUR = 2;

    /**
     * @param array<string, mixed> $arbre
     * @param array<string, array<string, mixed>> $ligne  ['valeurs' => [code => ?float], 'precedentes' => [code => ?float]]
     * @return array{ok: bool, constats: array<int, array<string, mixed>>}
     */
    public static function evaluer(array $arbre, array $ligne): array
    {
        return self::noeud($arbre, $ligne);
    }

    /**
     * Contrôle la structure et les références d'un arbre. Retourne la liste des erreurs (vide si valide).
     *
     * @param array<string, mixed> $arbre
     * @param array<int, string> $indicateursConnus codes d'indicateurs utilisables
     * @return array<int, string>
     */
    public static function verifier(array $arbre, array $indicateursConnus): array
    {
        $erreurs = [];
        $feuilles = 0;
        self::controler($arbre, $indicateursConnus, 1, $feuilles, $erreurs);

        if ($feuilles === 0) {
            $erreurs[] = 'Ajoutez au moins une condition.';
        }
        if ($feuilles > self::MAX_CONDITIONS) {
            $erreurs[] = 'Au plus ' . self::MAX_CONDITIONS . ' conditions par règle.';
        }

        return array_values(array_unique($erreurs));
    }

    /** @return array<int, string> codes des indicateurs utilisés (indicateur et indicateur_b) */
    public static function indicateursUtilises(array $arbre): array
    {
        $codes = [];
        foreach ((array) ($arbre['conditions'] ?? []) as $n) {
            if (isset($n['conditions'])) {
                $codes = array_merge($codes, self::indicateursUtilises($n));
            } else {
                $codes[] = (string) ($n['indicateur'] ?? '');
                if (! empty($n['indicateur_b'])) {
                    $codes[] = (string) $n['indicateur_b'];
                }
            }
        }

        return array_values(array_unique(array_filter($codes)));
    }

    // ------------------------------------------------------------------

    /** @return array{ok: bool, constats: array<int, array<string, mixed>>} */
    private static function noeud(array $n, array $ligne): array
    {
        if (isset($n['conditions'])) {
            $ou = ($n['operateur'] ?? 'et') === 'ou';
            $constats = [];
            $nbOk = 0;
            $total = 0;

            foreach ((array) $n['conditions'] as $enfant) {
                $total++;
                $r = self::noeud((array) $enfant, $ligne);
                if ($r['ok']) {
                    $nbOk++;
                    $constats = array_merge($constats, $r['constats']);
                }
            }

            $ok = $total > 0 && ($ou ? $nbOk > 0 : $nbOk === $total);

            return ['ok' => $ok, 'constats' => $ok ? $constats : []];
        }

        $observe = self::mesurer($n, $ligne);
        $ok = self::comparer($observe, (string) $n['comparateur'], $n['valeur'] ?? null);

        return [
            'ok' => $ok,
            'constats' => $ok ? [[
                'indicateur' => $n['indicateur'],
                'indicateur_b' => $n['indicateur_b'] ?? null,
                'mesure' => $n['mesure'],
                'comparateur' => $n['comparateur'],
                'seuil' => $n['valeur'] ?? null,
                'observe' => $observe,
            ]] : [],
        ];
    }

    private static function mesurer(array $c, array $ligne): ?float
    {
        $v = $ligne['valeurs'][$c['indicateur']] ?? null;
        $p = $ligne['precedentes'][$c['indicateur']] ?? null;

        switch ($c['mesure']) {
            case 'valeur':
                return $v;
            case 'baisse_pct_n1':
                return ($v === null || $p === null || $p <= 0) ? null : round(($p - $v) / $p * 100, 2);
            case 'hausse_pct_n1':
                return ($v === null || $p === null || $p <= 0) ? null : round(($v - $p) / $p * 100, 2);
            case 'ecart_n1':
                return ($v === null || $p === null) ? null : round($v - $p, 2);
            case 'ratio_pct':
                $b = $ligne['valeurs'][$c['indicateur_b'] ?? ''] ?? null;

                return ($v === null || $b === null || $b == 0) ? null : round($v / $b * 100, 2);
        }

        return null;
    }

    private static function comparer(?float $observe, string $comparateur, mixed $seuil): bool
    {
        if ($comparateur === 'vide') {
            return $observe === null;
        }
        if ($comparateur === 'non_vide') {
            return $observe !== null;
        }
        if ($observe === null || ! is_numeric($seuil)) {
            return false;
        }

        $s = (float) $seuil;

        return match ($comparateur) {
            '>' => $observe > $s,
            '>=' => $observe >= $s,
            '<' => $observe < $s,
            '<=' => $observe <= $s,
            '=' => abs($observe - $s) < 1e-9,
            '!=' => abs($observe - $s) >= 1e-9,
            default => false,
        };
    }

    private static function controler(array $n, array $connus, int $profondeur, int &$feuilles, array &$erreurs): void
    {
        if (isset($n['conditions'])) {
            if ($profondeur > self::MAX_PROFONDEUR) {
                $erreurs[] = 'Groupes de conditions : ' . self::MAX_PROFONDEUR . ' niveaux au plus.';

                return;
            }
            if (! in_array($n['operateur'] ?? null, ['et', 'ou'], true)) {
                $erreurs[] = "L'opérateur d'un groupe doit être « et » ou « ou ».";
            }
            if (! is_array($n['conditions']) || $n['conditions'] === []) {
                $erreurs[] = 'Un groupe de conditions ne peut pas être vide.';

                return;
            }
            foreach ($n['conditions'] as $enfant) {
                if (! is_array($enfant)) {
                    $erreurs[] = 'Condition invalide.';
                    continue;
                }
                self::controler($enfant, $connus, $profondeur + 1, $feuilles, $erreurs);
            }

            return;
        }

        $feuilles++;
        $ind = $n['indicateur'] ?? null;
        $mesure = $n['mesure'] ?? null;
        $comp = $n['comparateur'] ?? null;

        if (! is_string($ind) || ! in_array($ind, $connus, true)) {
            $erreurs[] = 'Indicateur inconnu ou désactivé : « ' . (is_scalar($ind) ? $ind : '?') . ' ».';
        }
        if (! in_array($mesure, self::MESURES, true)) {
            $erreurs[] = 'Mesure inconnue : « ' . (is_scalar($mesure) ? $mesure : '?') . ' ».';
        }
        if (! in_array($comp, self::COMPARATEURS, true)) {
            $erreurs[] = 'Comparateur inconnu : « ' . (is_scalar($comp) ? $comp : '?') . ' ».';

            return;
        }
        if (in_array($comp, ['vide', 'non_vide'], true)) {
            if ($mesure !== 'valeur') {
                $erreurs[] = 'Les tests « vide » et « renseigné » ne s\'appliquent qu\'à la valeur de l\'indicateur.';
            }
        } elseif (! is_numeric($n['valeur'] ?? null)) {
            $erreurs[] = 'Chaque condition a besoin d\'une valeur numérique.';
        } elseif (in_array($mesure, ['baisse_pct_n1', 'hausse_pct_n1'], true) && abs((float) $n['valeur']) > 100000) {
            $erreurs[] = 'Pourcentage hors limites.';
        }
        if ($mesure === 'ratio_pct') {
            $b = $n['indicateur_b'] ?? null;
            if (! is_string($b) || ! in_array($b, $connus, true)) {
                $erreurs[] = 'Le ratio demande un second indicateur valide.';
            }
        }
    }
}
