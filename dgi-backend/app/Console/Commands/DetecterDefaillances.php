<?php

namespace App\Console\Commands;

use App\Services\AnomalieAnalyseur;
use App\Services\DefaillanceDetector;
use Illuminate\Console\Command;

/**
 * Lance la détection des anomalies (défaillance de déclaration + baisse du chiffre d'affaires)
 * en ligne de commande ou via le planificateur (toutes les 5 minutes, voir routes/console.php) :
 *   php artisan dgi:detecter-defaillances
 *   php artisan dgi:detecter-defaillances --periode=2026-09 --centre=1
 */
class DetecterDefaillances extends Command
{
    protected $signature = "dgi:detecter-defaillances {--periode= : Periode de reference AAAA-MM (defaut : mois precedent)} {--centre= : ID d'un centre (defaut : tous)}";

    protected $description = "Détecte les anomalies : défaillances de déclaration et baisses du chiffre d'affaires";

    public function handle(AnomalieAnalyseur $analyseur): int
    {
        $periode = (string) ($this->option('periode') ?: DefaillanceDetector::periodePrecedente());

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
            $this->error('Période invalide : utilisez le format AAAA-MM (ex. 2026-09).');

            return self::FAILURE;
        }

        $centre = $this->option('centre') !== null ? (int) $this->option('centre') : null;
        $stats = $analyseur->analyser($periode, $centre);

        $this->info("Période {$stats['periode']} :");
        $this->line("  Défaillances : {$stats['defaillance']['analyses']} couple(s) contribuable/impôt analysé(s), {$stats['defaillance']['crees']} nouvelle(s) alerte(s)");
        $this->line("  Baisses du CA : {$stats['baisse_ca']['analyses']} déclaration(s) analysée(s), {$stats['baisse_ca']['crees']} nouvelle(s) alerte(s)");
        $this->line("Total : {$stats['crees']} nouvelle(s) | {$stats['mis_a_jour']} mise(s) à jour | {$stats['regularisees']} régularisée(s)");

        return self::SUCCESS;
    }
}
