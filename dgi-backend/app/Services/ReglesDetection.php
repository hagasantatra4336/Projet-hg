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
    public const CODES = ['defaillance', 'baisse_ca', 'r1', 'r2', 'r3', 'r5', 'r6', 'r7'];

    /** Critères de risque calculés sur la base d'analyse risque (R4 = « defaillance »). Réglages par défaut : config/risque.php. */
    public const CRITERES_R = ['r1', 'r2', 'r3', 'r5', 'r6', 'r7'];

    /** @return array{nom: string, description: string} */
    public static function meta(string $code): array
    {
        return match ($code) {
            'defaillance' => ['nom' => 'Défaut de dépôt de déclaration (critère R4)', 'description' => ''],
            'baisse_ca' => ['nom' => "Baisse du chiffre d'affaires", 'description' => ''],
            'r1' => ['nom' => 'Écart entre CA IR et CA TVA (critère R1)', 'description' => ''],
            'r2' => ['nom' => 'Déficit chronique (critère R2)', 'description' => ''],
            'r3' => ['nom' => 'Crédit de TVA permanent (critère R3)', 'description' => ''],
            'r5' => ['nom' => 'Discordance avec le recoupement (critère R5)', 'description' => ''],
            'r6' => ['nom' => "Risques liés à l'importation (critère R6)", 'description' => ''],
            'r7' => ['nom' => "Risques liés à l'exportation (critère R7)", 'description' => ''],
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
            'r1' => [
                self::reglageFenetre(),
                ['cle' => 'seuil_moyen', 'label' => 'Score au-dessus duquel le risque est moyen (orange)', 'aide' => 'Score sur 10. Vert jusqu\'à ce seuil compris.', 'type' => 'decimal', 'min' => 0, 'max' => 10],
                ['cle' => 'seuil_eleve', 'label' => 'Score au-dessus duquel le risque est élevé (rouge)', 'aide' => 'Score sur 10, au moins égal au seuil « moyen ».', 'type' => 'decimal', 'min' => 0, 'max' => 10],
            ],
            'r2' => [
                self::reglageFenetre(),
                ['cle' => 'seuil_moyen', 'label' => 'Score à partir duquel le risque est moyen (orange)', 'aide' => 'Score sur 5 : 1 exercice déficitaire = 1 ; 2 = 2,5 ; 3 = 5.', 'type' => 'decimal', 'min' => 0, 'max' => 5],
                ['cle' => 'seuil_eleve', 'label' => 'Score à partir duquel le risque est élevé (rouge)', 'aide' => 'Score sur 5, au moins égal au seuil « moyen ».', 'type' => 'decimal', 'min' => 0, 'max' => 5],
            ],
            'r3' => [
                self::reglageFenetre(),
                ['cle' => 'seuil_moyen', 'label' => 'Mois créditeurs consécutifs à partir desquels le risque est moyen (orange)', 'aide' => 'Score sur 5 = nombre de mois (5 mois et plus : 5).', 'type' => 'decimal', 'min' => 1, 'max' => 5],
                ['cle' => 'seuil_eleve', 'label' => 'Mois créditeurs consécutifs à partir desquels le risque est élevé (rouge)', 'aide' => 'Au moins égal au seuil « moyen ».', 'type' => 'decimal', 'min' => 1, 'max' => 5],
            ],
            'r5' => [
                self::reglageFenetre(),
                ['cle' => 'taux_tva', 'label' => 'Taux de TVA utilisé pour estimer le CA non déclaré (%)', 'aide' => '20 % dans le document de référence.', 'type' => 'decimal', 'min' => 1, 'max' => 100],
                ['cle' => 'marge', 'label' => 'Marge supposée sur les achats (%)', 'aide' => '10 % dans le document de référence.', 'type' => 'decimal', 'min' => 0, 'max' => 500],
                ['cle' => 'seuil_moyen', 'label' => 'Score à partir duquel le risque est moyen (orange)', 'aide' => 'Score sur 5 (moyenne des scores IR et TVA).', 'type' => 'decimal', 'min' => 0, 'max' => 5],
                ['cle' => 'seuil_eleve', 'label' => 'Score à partir duquel le risque est élevé (rouge)', 'aide' => 'Score sur 5, au moins égal au seuil « moyen ».', 'type' => 'decimal', 'min' => 0, 'max' => 5],
            ],
            'r6', 'r7' => [
                self::reglageFenetre(),
                ['cle' => 'seuil_moyen', 'label' => 'Score au-dessus duquel le risque est moyen (orange)', 'aide' => 'Score sur 10. Le document de référence ne fixe pas de seuils : ceux de R1 sont proposés.', 'type' => 'decimal', 'min' => 0, 'max' => 10],
                ['cle' => 'seuil_eleve', 'label' => 'Score au-dessus duquel le risque est élevé (rouge)', 'aide' => 'Score sur 10, au moins égal au seuil « moyen ».', 'type' => 'decimal', 'min' => 0, 'max' => 10],
            ],
            default => [],
        };
    }

    /** Réglage commun aux critères R : nombre d'exercices analysés en plus de l'année en cours. @return array<string, mixed> */
    private static function reglageFenetre(): array
    {
        return ['cle' => 'fenetre_exercices', 'label' => "Exercices analysés en plus de l'année en cours", 'aide' => '0 : année en cours seulement ; 1 : année en cours et année précédente.', 'type' => 'entier', 'min' => 0, 'max' => 5];
    }

    /** Réglages par défaut d'une règle : config/anomalies.php, ou config/risque.php pour les critères R. @return array<string, mixed> */
    private static function configDe(string $code): array
    {
        return in_array($code, self::CRITERES_R, true) ? (array) config("risque.regles.{$code}") : (array) config("anomalies.{$code}");
    }

    /** @return array<int, string> clés des réglages modifiables */
    public static function cles(string $code): array
    {
        return array_column(self::schema($code), 'cle');
    }

    /** Valeurs par défaut (config/anomalies.php) des réglages modifiables. @return array<string, mixed> */
    public static function defauts(string $code): array
    {
        return array_intersect_key(self::configDe($code), array_flip(self::cles($code)));
    }

    /**
     * Réglages EN VIGUEUR : config/anomalies.php, remplacée par les réglages enregistrés dans l'application.
     * Les réglages non modifiables (ex. périodicités par défaut) restent ceux de la config.
     *
     * @return array<string, mixed>
     */
    public static function parametres(string $code): array
    {
        $base = self::configDe($code);
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
                        // Les critères R n'ont pas de liste d'impôts : seule l'activation compte
                        $impots = self::parametres($code)['impots'] ?? null;
                        if ($impots !== null) {
                            $r->whereIn('alertes.type_impot', array_values((array) $impots));
                        }
                    } else {
                        $r->whereRaw('1 = 0');
                    }
                });
            }

            // Règles personnalisées : seules les alertes des règles ACTIVES sont visibles, et seulement pour leurs impôts.
            // Brouillon / désactivée : alertes masquées (ni supprimées ni modifiées).
            $personnalisees = self::personnalisees();
            foreach ($personnalisees as $r) {
                if ($r->statut === Regle::ACTIVE && $r->actif) {
                    $q->orWhere(function ($s) use ($r) {
                        $s->where('alertes.regle', $r->code)
                            ->whereIn('alertes.type_impot', array_values((array) $r->impots));
                    });
                }
            }

            $q->orWhereNotIn('alertes.regle', array_merge(self::CODES, $personnalisees->pluck('code')->all())); // règles futures : non filtrées
        });
    }

    /**
     * Règles personnalisées (toutes, quel que soit leur statut). Vide si la migration de l'étape 2 n'est pas lancée.
     *
     * @return \Illuminate\Support\Collection<int, Regle>
     */
    public static function personnalisees(): \Illuminate\Support\Collection
    {
        try {
            return Regle::where('type', Regle::PERSONNALISEE)->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /** Codes des règles personnalisées (pour valider les filtres de l'écran des alertes). @return array<int, string> */
    public static function codesPersonnalises(): array
    {
        return self::personnalisees()->pluck('code')->all();
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

        if (in_array($code, self::CRITERES_R, true)) {
            if ((float) $p['seuil_moyen'] > (float) $p['seuil_eleve']) {
                $erreurs[] = 'Le seuil « risque moyen » ne peut pas dépasser le seuil « risque élevé ».';
            }
        }

        return $erreurs;
    }
}
