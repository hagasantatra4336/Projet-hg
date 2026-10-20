<?php

return [
    // URL du front React : sert à construire les liens envoyés par e-mail (confirmation de modification du profil).
    // À définir dans .env :  FRONTEND_URL=http://localhost:5173   puis : php artisan config:clear
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Base PostgreSQL SOURCE (voir config/database.php, connexion « source »). Elle n'est jamais modifiée (SELECT uniquement).
    // Les noms de colonnes ci-dessous sont ceux des exemples fournis (déclarations IR et TVA, assujettissement,
    // centres gestionnaires, info contribuable). La casse n'a pas d'importance (FISCAL_NO ou fiscal_no).
    // Si votre base réelle a d'autres noms, changez-les ici, puis : php artisan config:clear
    'source' => [
        'connection' => env('DGI_SOURCE_CONNECTION', 'source'),

        // Déclarations : UNE TABLE PAR IMPÔT, au format « une ligne par ligne du formulaire » (IRD_LINE_NO).
        //   ligne_ca : numéro de la ligne qui contient le chiffre d'affaires (IR : ligne 15 ; TVA : ligne 170)
        //   mois     : colonne du mois (vide ou 0 pour une déclaration annuelle)
        //   version  : colonne de version (déclarations rectificatives) : seule la dernière version est retenue ; null si absente
        // Pour ajouter l'IS ou l'IRSA, copiez un bloc ci-dessous avec le nom de leur table et la ligne de leur CA.
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

        // Obligations déclaratives : un impôt, une année, une périodicité (1 mensuel, 5 annuel...), actif / exonéré
        'assujettissements' => [
            'table' => env('DGI_SOURCE_TABLE_ASSUJETTISSEMENT', 'assujettissement'),
            'colonnes' => [
                'nif' => 'NIF', 'annee' => 'ANNEE', 'debut' => 'DEBUT', 'fin' => 'FIN', 'periodicite' => 'PERIODICITE',
                'impot' => 'ABREV', 'actif' => 'ACTIF', 'exonere' => 'EXONERE',
            ],
        ],

        // Contribuables : nom, centre gestionnaire, état et dates de fin d'activité.
        // centre_code (facultatif) : colonne contenant l'identifiant du centre (« 21-1-702 »), plus fiable que le nom.
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
