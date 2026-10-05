<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Détection automatique des défaillances de déclaration, toutes les 5 minutes (tous les centres,
// période de référence = mois précédent). Voir « schedule:work » / cron dans le LISEZ-MOI.
Schedule::command('dgi:detecter-defaillances')
    ->everyMinute()
    ->withoutOverlapping(5);
