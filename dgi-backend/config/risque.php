<?php

/**
 * Base d'analyse risque (critères R1, R2, R3, R5, R6, R7) : où lire les valeurs dans la base source.
 * Modifiez les numéros de lignes ou les noms de colonnes ici (ou dans le .env), puis : php artisan config:clear
 * La casse des noms de colonnes n'a pas d'importance.
 */
return [
    // Lignes des formulaires. Plusieurs lignes = leur somme (ex. TVA déductible sur investissements locaux : 315 + 316 + 320).
    // Les tables et colonnes des déclarations IR et TVA sont celles de config/dgi.php (source.declarations).
    'lignes' => [
        'IR' => [
            'ca' => [15],
            'resultat' => [20],
            'reduction' => [125],
            'ir_a_payer' => [115],
        ],
        'TVA' => [
            'ca' => [170],
            'ca_taxable' => [150],
            'ca_exonere' => [160],
            'tva_deduc_bl' => [300],              // biens locaux
            'tva_deduc_bi' => [305],              // biens importés
            'tva_deduc_il' => [315, 316, 320],    // investissements locaux
            'tva_deduc_ii' => [330, 335],         // investissements importés
            'tva_deduc_abl' => [340],             // autres biens locaux
            'tva_deduc_abi' => [345],             // autres biens importés
            'credit_tva' => [400],
            'ca_taxable_export' => [100],
        ],
    ],

    // Recoupement (récapitulatif par NIF et par année) : une table, une ligne par NIF et année
    'recoupement' => [
        'table' => env('DGI_SOURCE_TABLE_RECOUPEMENT', 'a_tab_recoupement'),
        'colonnes' => [
            'nif' => 'NIF', 'annee' => 'ANNEE',
            'prestations_de_service' => 'PRESTATIONS_DE_SERVICE', 'achats_effectues' => 'ACHATS_EFFECTUES',
            'achats_consommes' => 'ACHATS_CONSOMMES', 'produits_locaux_vendus' => 'PRODUITS_LOCAUX_VENDUS',
            'exportations' => 'EXPORTATIONS', 'importations' => 'IMPORTATIONS', 'montant_total' => 'MONTANT_TOTAL',
            'tva_ded_achat' => 'TVA_DED_ACHAT', 'tva_ded_imp' => 'TVA_DED_IMP', 'marche_public' => 'MARCHER_PUBLIC',
        ],
    ],

    // DAU (déclarations en douane) : une ligne par DAU. « modele » : colonne qui contient IM4 (importation) ou EX1 (exportation).
    // Si la source n'a qu'une date, créez une vue qui expose l'année (extract(year from ...)).
    'dau' => [
        'table' => env('DGI_SOURCE_TABLE_DAU', 'a_tab_dau'),
        'colonnes' => ['nif' => 'NIF', 'annee' => 'ANNEE', 'modele' => 'MODELE', 'tva' => 'TVA', 'valeur_caf' => 'VALEUR_CAF'],
        'modele_import' => 'IM4',
        'modele_export' => 'EX1',
    ],

    // Réglages par défaut des critères (modifiables ensuite dans « Règles de détection »).
    // fenetre_exercices : exercices analysés en plus de l'année en cours. Seuils : voir le document de référence.
    'regles' => [
        'r1' => ['fenetre_exercices' => 1, 'seuil_moyen' => 3.0, 'seuil_eleve' => 6.0],   // orange : 3 < R1 <= 6 ; rouge : R1 > 6
        'r2' => ['fenetre_exercices' => 1, 'seuil_moyen' => 2.5, 'seuil_eleve' => 5.0],   // orange : R2 = 2,5 ; rouge : R2 = 5
        'r3' => ['fenetre_exercices' => 1, 'seuil_moyen' => 3.0, 'seuil_eleve' => 5.0],   // orange : 3 à 4 mois ; rouge : 5 mois et plus
        'r5' => ['fenetre_exercices' => 1, 'seuil_moyen' => 3.0, 'seuil_eleve' => 5.0, 'taux_tva' => 20.0, 'marge' => 10.0],
        'r6' => ['fenetre_exercices' => 1, 'seuil_moyen' => 3.0, 'seuil_eleve' => 6.0],   // seuils non précisés dans le document : ceux de R1
        'r7' => ['fenetre_exercices' => 1, 'seuil_moyen' => 3.0, 'seuil_eleve' => 6.0],
    ],
];
