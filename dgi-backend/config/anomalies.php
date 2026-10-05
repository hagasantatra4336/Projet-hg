<?php

/**
 * Règles de détection d'anomalies (modifiables sans toucher au code : EXNF-004).
 * Chaque valeur peut être changée ici ou dans le .env, puis : php artisan config:clear
 */
return [
    'defaillance' => [
        // Impôts analysés (déclarations mensuelles)
        'impots' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANOMALIES_IMPOTS', 'TVA,IR,IS,IRSA'))))),

        // Un contribuable est "habituellement déclarant" s'il a déclaré au moins ce nombre de mois consécutifs
        // juste avant la rupture (EXF-004 : 6 mois).
        'serie_minimale' => (int) env('ANOMALIES_DEFAILLANCE_SERIE_MIN', 6),

        // Niveau de risque selon le nombre de mois consécutifs manqués
        //   < seuil_moyen  → faible ;  >= seuil_moyen → moyen ;  >= seuil_eleve → élevé
        'seuil_moyen' => (int) env('ANOMALIES_DEFAILLANCE_SEUIL_MOYEN', 2),
        'seuil_eleve' => (int) env('ANOMALIES_DEFAILLANCE_SEUIL_ELEVE', 3),

        // Gravité aggravée : si le contribuable était très régulier (au moins ce nombre de mois consécutifs),
        // le niveau monte d'un cran (la rupture est plus suspecte).
        'serie_longue' => (int) env('ANOMALIES_DEFAILLANCE_SERIE_LONGUE', 12),
    ],
];
