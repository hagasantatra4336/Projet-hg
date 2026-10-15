<?php

namespace App\Services;

/**
 * Modèles de règles : points de départ proposés à l'utilisateur. Un modèle + ses paramètres produisent un arbre de
 * conditions ; le brouillon peut ensuite être modifié librement (conditions combinables ET/OU).
 * Fonctions pures (aucun accès base).
 */
final class ModelesRegles
{
    public const CODES = ['baisse_indicateur', 'hausse_indicateur', 'seuil_depasse', 'valeur_manquante', 'ratio_anormal', 'baisse_et_seuil'];

    /** @return array<int, array<string, mixed>> catalogue (pour l'écran de création) */
    public static function catalogue(): array
    {
        $comp = ['>', '>=', '<', '<='];

        return [
            [
                'code' => 'baisse_indicateur',
                'nom' => "Baisse d'un indicateur",
                'description' => "Alerte si l'indicateur baisse d'au moins X % par rapport à la même période de l'année précédente.",
                'niveau_defaut' => 'moyen',
                'parametres' => [
                    ['cle' => 'indicateur', 'type' => 'indicateur'],
                    ['cle' => 'seuil_pct', 'type' => 'decimal', 'defaut' => 30, 'min' => 1, 'max' => 100],
                ],
            ],
            [
                'code' => 'hausse_indicateur',
                'nom' => "Hausse d'un indicateur",
                'description' => "Alerte si l'indicateur augmente d'au moins X % par rapport à la même période de l'année précédente.",
                'niveau_defaut' => 'faible',
                'parametres' => [
                    ['cle' => 'indicateur', 'type' => 'indicateur'],
                    ['cle' => 'seuil_pct', 'type' => 'decimal', 'defaut' => 100, 'min' => 1, 'max' => 100000],
                ],
            ],
            [
                'code' => 'seuil_depasse',
                'nom' => 'Valeur au-dessus ou au-dessous d\'un seuil',
                'description' => "Alerte si la valeur déclarée de l'indicateur dépasse (ou n'atteint pas) un montant.",
                'niveau_defaut' => 'faible',
                'parametres' => [
                    ['cle' => 'indicateur', 'type' => 'indicateur'],
                    ['cle' => 'comparateur', 'type' => 'choix', 'options' => $comp, 'defaut' => '>='],
                    ['cle' => 'valeur', 'type' => 'decimal'],
                ],
            ],
            [
                'code' => 'valeur_manquante',
                'nom' => 'Valeur non renseignée',
                'description' => "Alerte si une déclaration déposée n'indique pas la valeur de l'indicateur.",
                'niveau_defaut' => 'faible',
                'parametres' => [['cle' => 'indicateur', 'type' => 'indicateur']],
            ],
            [
                'code' => 'ratio_anormal',
                'nom' => 'Ratio entre deux indicateurs',
                'description' => "Alerte si indicateur A / indicateur B (en %) dépasse, ou n'atteint pas, un seuil.",
                'niveau_defaut' => 'moyen',
                'parametres' => [
                    ['cle' => 'indicateur', 'type' => 'indicateur'],
                    ['cle' => 'indicateur_b', 'type' => 'indicateur'],
                    ['cle' => 'comparateur', 'type' => 'choix', 'options' => $comp, 'defaut' => '<='],
                    ['cle' => 'valeur', 'type' => 'decimal'],
                ],
            ],
            [
                'code' => 'baisse_et_seuil',
                'nom' => "Baisse d'un indicateur ET valeur d'un autre sous un plafond",
                'description' => "Exemple de règle combinée : l'indicateur A baisse d'au moins X % ET la valeur de l'indicateur B reste inférieure ou égale à un plafond.",
                'niveau_defaut' => 'eleve',
                'parametres' => [
                    ['cle' => 'indicateur', 'type' => 'indicateur'],
                    ['cle' => 'seuil_pct', 'type' => 'decimal', 'defaut' => 30, 'min' => 1, 'max' => 100],
                    ['cle' => 'indicateur_b', 'type' => 'indicateur'],
                    ['cle' => 'valeur', 'type' => 'decimal', 'defaut' => 0],
                ],
            ],
        ];
    }

    public static function existe(string $modele): bool
    {
        return in_array($modele, self::CODES, true);
    }

    /**
     * Arbre de conditions d'un modèle. Les paramètres sont vérifiés ensuite par EvaluateurConditions::verifier().
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function construire(string $modele, array $p): array
    {
        $feuille = fn (string $ind, string $mesure, string $comp, mixed $val = null, ?string $b = null): array => array_filter(
            ['indicateur' => $ind, 'mesure' => $mesure, 'comparateur' => $comp, 'valeur' => $val, 'indicateur_b' => $b],
            fn ($x) => $x !== null
        );
        $ind = (string) ($p['indicateur'] ?? '');
        $b = isset($p['indicateur_b']) ? (string) $p['indicateur_b'] : null;
        $comp = in_array($p['comparateur'] ?? null, ['>', '>=', '<', '<='], true) ? $p['comparateur'] : '>=';

        $conditions = match ($modele) {
            'baisse_indicateur' => [$feuille($ind, 'baisse_pct_n1', '>=', $p['seuil_pct'] ?? 30)],
            'hausse_indicateur' => [$feuille($ind, 'hausse_pct_n1', '>=', $p['seuil_pct'] ?? 100)],
            'seuil_depasse' => [$feuille($ind, 'valeur', $comp, $p['valeur'] ?? null)],
            'valeur_manquante' => [$feuille($ind, 'valeur', 'vide')],
            'ratio_anormal' => [$feuille($ind, 'ratio_pct', $comp, $p['valeur'] ?? null, $b)],
            'baisse_et_seuil' => [
                $feuille($ind, 'baisse_pct_n1', '>=', $p['seuil_pct'] ?? 30),
                $feuille((string) $b, 'valeur', '<=', $p['valeur'] ?? 0),
            ],
            default => [],
        };

        return ['operateur' => 'et', 'conditions' => $conditions];
    }

    public static function niveauDefaut(string $modele): string
    {
        foreach (self::catalogue() as $m) {
            if ($m['code'] === $modele) {
                return $m['niveau_defaut'];
            }
        }

        return 'faible';
    }
}
