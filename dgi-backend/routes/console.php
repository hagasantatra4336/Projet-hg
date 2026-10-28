<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Détection automatique des défaillances de déclaration, chaque minute (tous les centres,
// période de référence = mois précédent). L'exécution effective est limitée à une fois
// toutes les ~5 minutes par le verrou du service AnalyseAutomatique.
// Voir « schedule:work » / cron dans le LISEZ-MOI.
Schedule::command('dgi:detecter-defaillances')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Purge quotidienne des jetons Sanctum expirés ou inutilisés depuis plus de 24 h : sans elle,
// personal_access_tokens grossirait indéfiniment (un jeton créé à chaque connexion).
Schedule::command('sanctum:prune-expired --hours=24')->daily();