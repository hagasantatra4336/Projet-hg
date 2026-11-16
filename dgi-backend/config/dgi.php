<?php

return [
    // À définir dans .env :  FRONTEND_URL=http://localhost:5173   puis : php artisan config:clear
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'source' => [
        // Connexion commune (repli) : voir config/database.php, connexion « source » (SOURCE_DB_*).
        'connection' => env('DGI_SOURCE_CONNECTION', 'source'),
        // Une base différente par groupe : mettre le nom d'une connexion de config/database.php
        // (source_declarations, source_contribuables, ...) et ses paramètres SOURCE_<GROUPE>_DB_*
        // dans le .env. Une valeur vide (ou absente) => connexion commune ci-dessus.
        'connections' => [
            'declarations' => env('DGI_SOURCE_CONNECTION_DECLARATIONS'),
            'assujettissements' => env('DGI_SOURCE_CONNECTION_ASSUJETTISSEMENTS'),
            'contribuables' => env('DGI_SOURCE_CONNECTION_CONTRIBUABLES'),
            'centres' => env('DGI_SOURCE_CONNECTION_CENTRES'),
            'agents' => env('DGI_SOURCE_CONNECTION_AGENTS'),
            // Tables de la base d'analyse risque (config/risque.php)
            'recoupement' => env('DGI_SOURCE_CONNECTION_RECOUPEMENT'),
            'dau' => env('DGI_SOURCE_CONNECTION_DAU'),
        ],

          'declarations' => [
            'IR' => [
                'table' => env('DGI_SOURCE_TABLE_IR', 'declaration_ir'),
                'ligne_ca' => (int) env('DGI_SOURCE_LIGNE_CA_IR', 15),
                'colonnes' => [
                    'nif' => 'FISCAL_NO', 'annee' => 'ANNEE', 'mois' => 'MOIS', 'ligne' => 'IRD_LINE_NO',
                    'montant' => 'MONTANT_SOUMIS', 'montant_liquide' => 'MONTANT_LIQUIDE',
                    'date_limite' => 'DATE_ECHEANCE', 'date_depot' => 'DATE_RECEPTION', 'version' => 'VERSION_NO',
                ],
            ],
            'TVA' => [
                'table' => env('DGI_SOURCE_TABLE_TVA', 'declaration_tva'),
                'ligne_ca' => (int) env('DGI_SOURCE_LIGNE_CA_TVA', 170),
                'colonnes' => [
                    'nif' => 'FISCAL_NO', 'annee' => 'ANNEE', 'mois' => 'MOIS', 'ligne' => 'IRD_LINE_NO',
                    'montant' => 'MONTANT_SOUMIS', 'montant_liquide' => 'MONTANT_LIQUIDE',
                    'date_limite' => 'DATE_ECHEANCE', 'date_depot' => 'DATE_RECEPTION', 'version' => null,
                ],
            ],
        ],

       'assujettissements' => [
            'table' => env('DGI_SOURCE_TABLE_ASSUJETTISSEMENT', 'assujettissement'),
            'colonnes' => [
                'nif' => 'NIF', 'annee' => 'ANNEE', 'debut' => 'DEBUT', 'fin' => 'FIN', 'periodicite' => 'PERIODICITE',
                'impot' => 'ABREV', 'actif' => 'ACTIF', 'exonere' => 'EXONERE',
            ],
        ],

         'contribuables' => [
            'table' => env('DGI_SOURCE_TABLE_CONTRIBUABLES', 'info_contribuable'),
            'colonnes' => [
                'nif' => 'NIF', 'nom' => 'RAISON_SOCIALE', 'centre' => 'CENTRE_GEST',
                'centre_code' => env('DGI_SOURCE_CONTRIBUABLES_COL_CENTRE_CODE'),
                'etat' => 'ETAT', 'date_cessation' => 'DATE_CESSATION', 'date_radiation' => 'DATE_RADIE', 'date_blocage' => 'DATE_BLOQUE',
            ],
        ],

        // Centres gestionnaires officiels (identifiant, libellé, abréviation) : commande php artisan dgi:importer-centres
        'centres' => [
            'table' => env('DGI_SOURCE_TABLE_CENTRES', 'centre_gestionnaire'),
            'colonnes' => ['code' => 'CENTRE ID', 'libelle' => 'LIBELLE CENTRE', 'abreviation' => 'CENTRE ABREV'],
        ],

       
        'agents' => [
            'table' => env('DGI_SOURCE_TABLE_AGENTS', 'agents'),
            'colonnes' => [
                'email' => 'EMAIL',
                'matricule' => 'MATRICULE',
                'nom' => 'NOM',
                'centre_code' => 'CENTRE_CODE',
                'centre_libelle' => 'CENTRE_LIBELLE',
                'grade' => 'GRADE',
                'actif' => 'ACTIF',
            ],
        ],

        // Noms de centres ambigus de la base source => code ou nom du centre de l'application. Exemple :
        //   'ANTANANARIVO' => '21-1-702', 'MAHAJANGA' => 'CENTRE FISCAL MAHAJANGA A'
        // Jeu « fichiers Excel » : CENTRE_GEST donne la ville sans le sous-centre (A/B, I à VI).
        // Choix arbitraire du centre principal, à ajuster si vous connaissez le vrai sous-centre.
        'alias_centres' => [
            'ANTANANARIVO' => 'CF TANA I',
            'MAHAJANGA' => 'CF MAHAJANGA A',
            'FIANARANTSOA' => 'CF FIANARANTSOA A',
            'TOAMASINA' => 'CF TOAMASINA A',
        ],
    ],
];
