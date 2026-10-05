<?php

namespace App\Console\Commands;

use App\Services\DefaillanceDetector;
use Illuminate\Console\Command;

/**
 * Lance la détection des défaillances de déclaration en ligne de commande (ou via le planificateur) :
 *   php artisan dgi:detecter-defaillances
 *   php artisan dgi:detecter-defaillances --periode=2026-09 --centre=1
 */
class DetecterDefaillances extends Command
{
    protected $signature = "dgi:detecter-defaillances {--periode= : Periode de reference AAAA-MM (defaut : mois precedent)} {--centre= : ID d'un centre (defaut : tous)}";

    protected $description = 'Détecte les contribuables habituellement déclarants qui ont cessé de déposer';

    public function handle(DefaillanceDetector $detector): int
    {
        $periode = (string) ($this->option('periode') ?: DefaillanceDetector::periodePrecedente());

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
            $this->error('Période invalide : utilisez le format AAAA-MM (ex. 2026-09).');

            return self::FAILURE;
        }

        $centre = $this->option('centre') !== null ? (int) $this->option('centre') : null;
        $stats = $detector->analyser($periode, $centre);

        $this->info("Période {$stats['periode']} : {$stats['analyses']} couple(s) contribuable/impôt analysé(s).");
        $this->line("Nouvelles alertes : {$stats['crees']} | mises à jour : {$stats['mis_a_jour']} | régularisées : {$stats['regularisees']}");

        return self::SUCCESS;
    }
}
