<?php

namespace App\Services;

use App\Models\Regle;
use Throwable;

/**
 * Réglages des règles de détection.
 *
 * Valeurs par défaut : config/anomalies.php. Valeurs modifiées dans l'application : table « regles » (colonne « parametres »).
 * Les détecteurs appellent parametres() et actif() : ils utilisent donc toujours les réglages en vigueur, sans toucher au code.
 */
class ReglesDetection
{
    public const CODES = ['defaillance', 'baisse_ca'];

    /** @return array{nom: string, description: string} */
    public static function meta(string $code): array
    {
        return match ($code) {
            'defaillance' => ['nom' => 'Défaut de dépôt de déclaration (critère R4)', 'description' => ''],
            'baisse_ca' => ['nom' => "Baisse du chiffre d'affaires", 'description' => ''],
            default => ['nom' => $code, 'description' => ''],
        };
    }

    /**
     * Réglages modifiables d'une règle (libellé, aide, type, bornes). Types : entier, decimal, liste_impots, bareme.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(string $code): array
    {
        $impots = array_values((array) config('anomalies.defaillance.impots'));

        return match ($code) {
            'defaillance' => [
                ['cle' => 'impots', 'label' => 'Impôts contrôlés', 'aide' => 'Seuls ces impôts sont vérifiés.', 'type' => 'liste_impots', 'options' => $impots],
                ['cle' => 'fenetre_exercices', 'label' => 'Exercices analysés en plus de l\'année en cours', 'aide' => '0 : année en cours seulement ; 1 : année en cours et année précédente.', 'type' => 'entier', 'min' => 0, 'max' => 5],
                ['cle' => 'score_annuel', 'label' => 'Score d\'une déclaration annuelle non déposée (sur 5)', 'aide' => 'IR et IS : 5 dans le barème de la DGI.', 'type' => 'entier', 'min' => 1, 'max' => 5],
                ['cle' => 'bareme_mensuel', 'label' => 'Barème des déclarations mensuelles non déposées', 'aide' => 'Nombre de mois non déposés dans l\'exercice (TVA, IRSA) et score sur 5 correspondant. Les tranches ne doivent pas se chevaucher.', 'type' => 'bareme'],
                ['cle' => 'score_moyen', 'label' => 'Score à partir duquel le risque est moyen', 'aide' => 'Entre 1 et 5.', 'type' => 'entier', 'min' => 1, 'max' => 5],
                ['cle' => 'score_eleve', 'label' => 'Score à partir duquel le risque est élevé', 'aide' => 'Entre 1 et 5, au moins égal au score « moyen ».', 'type' => 'entier', 'min' => 1, 'max' => 5],
            ],
            'baisse_ca' => [
                ['cle' => 'impots', 'label' => 'Impôts contrôlés', 'aide' => 'Impôts dont la déclaration comporte un chiffre d\'affaires.', 'type' => 'liste_impots', 'options' => $impots],
                ['cle' => 'seuil_pourcentage', 'label' => 'Seuil de déclenchement (baisse en %)', 'aide' => 'Une alerte est créée à partir de cette baisse (niveau faible).', 'type' => 'decimal', 'min' => 1, 'max' => 100],
                ['cle' => 'seuil_moyen', 'label' => 'Baisse à partir de laquelle le risque est moyen (%)', 'aide' => 'Au moins égale au seuil de déclenchement.', 'type' => 'decimal', 'min' => 1, 'max' => 100],
                ['cle' => 'seuil_eleve', 'label' => 'Baisse à partir de laquelle le risque est élevé (%)', 'aide' => 'Au moins égale au seuil « moyen ».', 'type' => 'decimal', 'min' => 1, 'max' => 100],
                ['cle' => 'fenetre_annees', 'label' => 'Exercices analysés en plus de l\'année en cours', 'aide' => '1 : année en cours et année précédente.', 'type' => 'entier', 'min' => 0, 'max' => 5],
            ],
            default => [],
        };
    }

    /** @return array<int, string> clés des réglages modifiables */
    public static function cles(string $code): array
    {
        return array_column(self::schema($code), 'cle');
    }

    /** Valeurs par défaut (config/anomalies.php) des réglages modifiables. @return array<string, mixed> */
    public static function defauts(string $code): array
    {
        return array_intersect_key((array) config("anomalies.{$code}"), array_flip(self::cles($code)));
    }

    /**
     * Réglages EN VIGUEUR : config/anomalies.php, remplacée par les réglages enregistrés dans l'application.
     * Les réglages non modifiables (ex. périodicités par défaut) restent ceux de la config.
     *
     * @return array<string, mixed>
     */
    public static function parametres(string $code): array
    {
        $base = (array) config("anomalies.{$code}");
        $regle = self::regle($code);

        $surcharges = $regle?->parametres;
        if (! is_array($surcharges)) {
            return $base;
        }

        return array_replace($base, array_intersect_key($surcharges, array_flip(self::cles($code))));
    }

    /** La règle est-elle activée ? (activée par défaut si la table n'existe pas encore) */
    public static function actif(string $code): bool
    {
        $regle = self::regle($code);

        return $regle === null ? true : (bool) $regle->actif;
    }

    /**
     * Limite une requête sur les ALERTES à celles qui sont dans le périmètre des règles en vigueur :
     * règle activée ET impôt coché dans ses réglages. Une alerte hors périmètre (impôt décoché, règle désactivée)
     * n'est plus affichée (liste, compteurs, tableau de bord, fiche contribuable) ; elle n'est ni supprimée ni modifiée
     * et réapparaît si l'impôt est recoché ou la règle réactivée.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation $query
     */
    public static function limiterAlertes($query): void
    {
        $query->where(function ($q) {
            foreach (self::CODES as $code) {
                $q->orWhere(function ($r) use ($code) {
                    $r->where('alertes.regle', $code);

                    if (self::actif($code)) {
                        $r->whereIn('alertes.type_impot', array_values((array) (self::parametres($code)['impots'] ?? [])));
                    } else {
                        $r->whereRaw('1 = 0');
                    }
                });
            }

            $q->orWhereNotIn('alertes.regle', self::CODES); // règles futures : non filtrées
        });
    }

    private static function regle(string $code): ?Regle
    {
        try {
            return Regle::where('code', $code)->first();
        } catch (Throwable) {
            return null; // migration pas encore lancée : on garde les valeurs de config
        }
    }

    /**
     * Contrôles entre réglages (fonction pure, sans accès base). Retourne la liste des erreurs, vide si tout est cohérent.
     *
     * @param array<string, mixed> $p
     * @return array<int, string>
     */
    public static function verifier(string $code, array $p): array
    {
        $erreurs = [];

        if ($code === 'defaillance') {
            if ((int) $p['score_moyen'] > (int) $p['score_eleve']) {
                $erreurs[] = 'Le score « risque moyen » ne peut pas dépasser le score « risque élevé ».';
            }

            $tranches = array_values((array) $p['bareme_mensuel']);
            usort($tranches, fn ($a, $b) => $a[0] <=> $b[0]);

            foreach ($tranches as $i => [$min, $max]) {
                if ($min > $max) {
                    $erreurs[] = "Tranche du barème : « de {$min} à {$max} » est invalide (le minimum dépasse le maximum).";
                }
                if ($i > 0 && $min <= $tranches[$i - 1][1]) {
                    $erreurs[] = "Les tranches du barème se chevauchent (à partir de {$min} mois).";
                }
            }
        }

        if ($code === 'baisse_ca') {
            if (! ((float) $p['seuil_pourcentage'] <= (float) $p['seuil_moyen'] && (float) $p['seuil_moyen'] <= (float) $p['seuil_eleve'])) {
                $erreurs[] = 'Les seuils doivent être croissants : déclenchement, puis risque moyen, puis risque élevé.';
            }
        }

        return $erreurs;
    }
}
