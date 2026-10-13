<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Origines du front autorisées à appeler l'API. Par défaut : http://localhost:5173.
    // Pour tester depuis un smartphone sur le même réseau, ajoutez l'adresse du PC dans .env :
    //   CORS_ALLOWED_ORIGINS=http://localhost:5173,http://192.168.1.20:5173
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))))),

    // En développement local UNIQUEMENT (APP_ENV=local) : l'application peut aussi être ouverte depuis l'adresse du PC
    // sur le réseau local (ex. http://192.168.1.20:5173), notamment depuis un smartphone. Jamais actif en production.
    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'local'
        ? ['#^http://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}):\d{2,5}$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
