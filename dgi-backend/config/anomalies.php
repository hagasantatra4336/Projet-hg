<?php

/**
 * Règles de détection d'anomalies (modifiables sans toucher au code : EXNF-004).
 * Chaque valeur peut être changée ici ou dans le .env, puis : php artisan config:clear
 *
 * Les déclarations sont MENSUELLES (TVA, IRSA) ou ANNUELLES (IR, IS). Une déclaration = (contribuable, impôt,
 * année fiscale, mois ; mois = 0 si annuelle), avec un chiffre d'affaires (CA) et une date limite.
 */
return [
    // Défaillance de déclaration = critère R4 de la DGI « Défaut de dépôt de déclaration »
    'defaillance' => [
        // Impôts analysés
        'impots' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANOMALIES_IMPOTS', 'TVA,IR,IS,IRSA'))))),

        // Périodicité supposée quand le contribuable n'a pas d'assujettissement connu pour cet impôt
        // (l'obligation est alors déduite de sa première déclaration)
        'periodicite_defaut' => ['TVA' => 'mensuelle', 'IRSA' => 'mensuelle', 'IR' => 'annuelle', 'IS' => 'annuelle'],

        // Exercices analysés : année en cours + N précédentes (1 → année en cours et année précédente)
        'fenetre_exercices' => (int) env('ANOMALIES_DEFAILLANCE_FENETRE_EXERCICES', 1),

        // Barème R4 (score sur 5). Déclaration annuelle non déposée : 5 points.
        'score_annuel' => 5,
        // Déclarations mensuelles non déposées dans l'exercice : [nombre minimum, nombre maximum, score]
        'bareme_mensuel' => [[1, 4, 2], [5, 9, 3], [10, 12, 4]],

        // Niveau de risque d'après le score R4 : >= score_eleve → élevé (rouge) ; >= score_moyen → moyen (orange) ; sinon faible (vert)
        'score_moyen' => (int) env('ANOMALIES_DEFAILLANCE_SCORE_MOYEN', 4),
        'score_eleve' => (int) env('ANOMALIES_DEFAILLANCE_SCORE_ELEVE', 5),
    ],

    // Baisse du chiffre d'affaires déclaré : TVA = même mois de l'année précédente ; IR / IS = année précédente
    'baisse_ca' => [
        // Impôts dont la déclaration comporte un chiffre d'affaires
        'impots' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANOMALIES_CA_IMPOTS', 'TVA,IR'))))),

        // Seuil de déclenchement : baisse d'au moins X % (niveau « faible »)
        'seuil_pourcentage' => (float) env('ANOMALIES_CA_SEUIL_BAISSE', 30),

        // Niveaux de risque selon l'ampleur de la baisse : >= seuil_moyen → moyen ; >= seuil_eleve → élevé
        'seuil_moyen' => (float) env('ANOMALIES_CA_SEUIL_MOYEN', 50),
        'seuil_eleve' => (float) env('ANOMALIES_CA_SEUIL_ELEVE', 70),

        // Exercices analysés : année en cours + N précédentes
        'fenetre_annees' => (int) env('ANOMALIES_CA_FENETRE_ANNEES', 1),
    ],
];
