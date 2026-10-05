<?php

return [
    // URL du front React : sert à construire les liens envoyés par e-mail (confirmation de modification du profil).
    // À définir dans .env :  FRONTEND_URL=http://localhost:5173   puis : php artisan config:clear
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Source PostgreSQL des déclarations (voir config/database.php, connexion « source »).
    // « colonnes » : champ attendu par l'application => nom de la colonne dans la table source.
    // Si votre base réelle a d'autres noms, changez-les ici ou dans le .env, puis : php artisan config:clear
    'source' => [
        'connection' => env('DGI_SOURCE_CONNECTION', 'source'),
        'table' => env('DGI_SOURCE_TABLE', 'declarations_source'),
        'colonnes' => [
            'nif' => env('DGI_SOURCE_COL_NIF', 'nif'),
            'nom' => env('DGI_SOURCE_COL_NOM', 'nom'),
            'centre' => env('DGI_SOURCE_COL_CENTRE', 'centre'),
            'type_impot' => env('DGI_SOURCE_COL_TYPE_IMPOT', 'type_impot'),
            'periode' => env('DGI_SOURCE_COL_PERIODE', 'periode'),   // texte « AAAA-MM »
            'montant' => env('DGI_SOURCE_COL_MONTANT', 'montant'),
            'date_depot' => env('DGI_SOURCE_COL_DATE_DEPOT', 'date_depot'),
        ],
    ],
];
