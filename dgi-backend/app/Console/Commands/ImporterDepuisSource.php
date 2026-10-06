<?php

namespace App\Console\Commands;

use App\Services\AnomalieAnalyseur;
use App\Services\DefaillanceDetector;
use App\Services\SourceDeclarationImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Récupère les déclarations depuis la base PostgreSQL source puis (option) lance l'analyse des anomalies :
 *   php artisan dgi:importer-source --tester
 *   php artisan dgi:importer-source
 *   php artisan dgi:importer-source --depuis=2025-10 --jusqua=2026-09 --centre=1 --analyser
 */
class ImporterDepuisSource extends Command
{
    protected $signature = "dgi:importer-source
        {--tester : Teste seulement la connexion à la base source}
        {--depuis= : Première période à lire AAAA-MM (défaut : tout l'historique)}
        {--jusqua= : Dernière période à lire AAAA-MM (défaut : jusqu'à la fin)}
        {--centre= : ID d'un centre de l'application (défaut : tous)}
        {--analyser : Lance ensuite la détection des anomalies : défaillances et baisses du chiffre d'affaires (période de référence = --jusqua, sinon mois précédent)}";

    protected $description = "Importe les déclarations depuis la base PostgreSQL source (lecture seule) pour l'analyse";

    public function handle(SourceDeclarationImporter $importer, AnomalieAnalyseur $analyseur): int
    {
        try {
            if ($this->option('tester')) {
                $info = $importer->tester();
                $this->info("Connexion OK : base « {$info['base']} », table « {$info['table']} » ({$info['lignes']} ligne(s)).");
                if ($info['version'] !== '') {
                    $this->line($info['version']);
                }

                return self::SUCCESS;
            }

            $centre = $this->option('centre') !== null ? (int) $this->option('centre') : null;
            $stats = $importer->importer($this->option('depuis') ?: null, $this->option('jusqua') ?: null, $centre);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error("Impossible de lire la base source : {$e->getMessage()}");
            $this->line('Vérifiez SOURCE_DB_* dans le .env (puis php artisan config:clear) et que la table existe.');

            return self::FAILURE;
        }

        $this->info("{$stats['lues']} ligne(s) lue(s) : {$stats['importees']} importée(s), {$stats['rejetees']} rejetée(s), {$stats['contribuables_crees']} contribuable(s) créé(s).");

        foreach ($stats['erreurs'] as $erreur) {
            $this->warn($erreur);
        }

        if ($this->option('analyser')) {
            $periode = (string) ($this->option('jusqua') ?: DefaillanceDetector::periodePrecedente());
            $a = $analyseur->analyser($periode, $centre);

            $this->info("Analyse {$a['periode']} :");
            $this->line("  Défaillances : {$a['defaillance']['analyses']} couple(s) analysé(s), {$a['defaillance']['crees']} nouvelle(s) alerte(s)");
            $this->line("  Baisses du CA : {$a['baisse_ca']['analyses']} déclaration(s) analysée(s), {$a['baisse_ca']['crees']} nouvelle(s) alerte(s)");
            $this->info("Total : {$a['crees']} nouvelle(s) | {$a['mis_a_jour']} mise(s) à jour | {$a['regularisees']} régularisée(s)");
        }

        return self::SUCCESS;
    }
}
