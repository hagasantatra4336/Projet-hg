<?php

return [
    // URL du front React : sert à construire les liens envoyés par e-mail (confirmation de modification du profil).
    // À définir dans .env :  FRONTEND_URL=http://localhost:5173   puis : php artisan config:clear
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
];
