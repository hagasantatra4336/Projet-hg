<?php

namespace App\Console\Commands;

use App\Services\AnomalieAnalyseur;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * Lance la détection des anomalies (défaillance de déclaration + baisse du chiffre d'affaires)
 * en ligne de commande ou via le planificateur (toutes les 5 minutes, voir routes/console.php) :
 *   php artisan dgi:detecter-defaillances
 *   php artisan dgi:detecter-defaillances --date=2026-10-07 --centre=1
 *   php artisan dgi:detecter-defaillances --centre=1 --annees=3   (analyse rétroactive ponctuelle)
 */
class DetecterDefaillances extends Command
{
    protected $signature = "dgi:detecter-defaillances {--date= : Date d'analyse AAAA-MM-JJ (defaut : aujourd'hui)} {--centre= : ID d'un centre (defaut : tous)} {--annees= : Exercices analysés en plus de l'année de référence pour ce passage (0 a 5, defaut : réglage des règles)}";

    protected $description = "Détecte les anomalies : défaillances de déclaration et baisses du chiffre d'affaires";

    public function handle(AnomalieAnalyseur $analyseur): int
    {
        $date = AnomalieAnalyseur::aujourdhui();

        if ($this->option('date')) {
            $brute = (string) $this->option('date');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $brute);

            if ($d === false || $d->format('Y-m-d') !== $brute) {
                $this->error('Date invalide : utilisez le format AAAA-MM-JJ (ex. 2026-10-07).');

                return self::FAILURE;
            }
            $date = $d;
        }

        $centre = $this->option('centre') !== null ? (int) $this->option('centre') : null;

        $annees = null;
        if ($this->option('annees') !== null) {
            $brute = (string) $this->option('annees');
            if (! ctype_digit($brute) || (int) $brute > 5) {
                $this->error('Fenêtre invalide : --annees doit être un entier de 0 à 5.');

                return self::FAILURE;
            }
            $annees = (int) $brute;
            $this->warn("Fenêtre étendue à {$annees} exercice(s) précédent(s) pour ce passage uniquement ; les réglages des règles restent inchangés.");
        }

        $stats = $analyseur->analyser($date, $centre, $annees);

        $this->info("Analyse au {$date->format('d/m/Y')} :");
        $this->line("  Défaillances : {$stats['defaillance']['analyses']} couple(s) contribuable/impôt analysé(s), {$stats['defaillance']['crees']} nouvelle(s) alerte(s)");
        $this->line("  Baisses du CA : {$stats['baisse_ca']['analyses']} déclaration(s) analysée(s), {$stats['baisse_ca']['crees']} nouvelle(s) alerte(s)");
        $this->line("Total : {$stats['crees']} nouvelle(s) | {$stats['mis_a_jour']} mise(s) à jour | {$stats['regularisees']} régularisée(s)");

        return self::SUCCESS;
    }
}
