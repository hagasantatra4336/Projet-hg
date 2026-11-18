<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// L'analyse des anomalies est lancée MANUELLEMENT depuis la page Anomalies, sur l'année ou l'intervalle
// choisi : aucune détection automatique n'est planifiée. La commande `php artisan dgi:detecter-defaillances`
// reste disponible pour un lancement ponctuel en console.

// Purge quotidienne des jetons Sanctum expirés ou inutilisés depuis plus de 24 h : sans elle,
// personal_access_tokens grossirait indéfiniment (un jeton créé à chaque connexion).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Code d'approbation valable 24 h : rappel une heure avant l'expiration, puis clôture des
// demandes dont le code a expiré sans être saisi (l'agent doit déposer une nouvelle demande).
Schedule::command('dgi:rappeler-otp-approbation')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dgi:expirer-otp-approbation')->everyFiveMinutes()->withoutOverlapping();