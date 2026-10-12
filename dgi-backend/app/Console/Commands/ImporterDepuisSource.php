<?php

namespace App\Console\Commands;

use App\Services\AnomalieAnalyseur;
use App\Services\SourceDeclarationImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Récupère contribuables, assujettissements et déclarations depuis la base PostgreSQL source puis (option)
 * lance l'analyse des anomalies :
 *   php artisan dgi:importer-source --tester
 *   php artisan dgi:importer-source
 *   php artisan dgi:importer-source --depuis=2024 --jusqua=2026 --centre=1 --analyser
 * (Lancez d'abord une fois : php artisan dgi:importer-centres)
 */
class ImporterDepuisSource extends Command
{
    protected $signature = "dgi:importer-source
        {--tester : Teste seulement la connexion à la base source et la présence des tables}
        {--depuis= : Première année fiscale à lire AAAA (défaut : tout l'historique)}
        {--jusqua= : Dernière année fiscale à lire AAAA (défaut : jusqu'à la fin)}
        {--centre= : ID d'un centre de l'application (défaut : tous)}
        {--analyser : Lance ensuite la détection des anomalies : défaillances et baisses du chiffre d'affaires (à la date du jour)}";

    protected $description = "Importe contribuables, assujettissements et déclarations depuis la base PostgreSQL source (lecture seule)";

    public function handle(SourceDeclarationImporter $importer, AnomalieAnalyseur $analyseur): int
    {
        try {
            if ($this->option('tester')) {
                $info = $importer->tester();
                $this->info("Connexion OK : base « {$info['base']} ».");
                foreach ($info['tables'] as $t) {
                    $this->line($t['existe'] ? "  ✔ {$t['table']} ({$t['lignes']} ligne(s))" : "  ✘ {$t['table']} : INTROUVABLE");
                }
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
            $this->line('Vérifiez SOURCE_DB_* et DGI_SOURCE_* dans le .env (puis php artisan config:clear) et que les tables existent.');

            return self::FAILURE;
        }

        $this->info("{$stats['lues']} déclaration(s) lue(s) : {$stats['importees']} importée(s), {$stats['rejetees']} rejetée(s), {$stats['ignorees']} ignorée(s).");
        $this->line("{$stats['assujettissements']} assujettissement(s) importé(s), {$stats['contribuables_crees']} contribuable(s) créé(s).");

        foreach ($stats['tables_absentes'] as $table) {
            $this->warn("Table « {$table} » introuvable dans la base source : ignorée.");
        }

        if ($stats['sans_centre'] > 0) {
            $this->warn("{$stats['sans_centre']} contribuable(s) créé(s) SANS centre : seuls les rôles central et super admin verront leurs alertes.");
        }

        foreach ($stats['centres_non_resolus'] as $nom => $nb) {
            $this->warn("Centre « {$nom} » introuvable ou ambigu ({$nb} contribuable(s)) : lancez dgi:importer-centres, ou ajoutez un alias dans config/dgi.php (source.alias_centres).");
        }

        foreach ($stats['erreurs'] as $erreur) {
            $this->warn($erreur);
        }

        if ($this->option('analyser')) {
            $a = $analyseur->analyser(AnomalieAnalyseur::aujourdhui(), $centre);

            $this->info("Analyse au {$a['date']} :");
            $this->line("  Défaillances : {$a['defaillance']['analyses']} couple(s) analysé(s), {$a['defaillance']['crees']} nouvelle(s) alerte(s)");
            $this->line("  Baisses du CA : {$a['baisse_ca']['analyses']} déclaration(s) analysée(s), {$a['baisse_ca']['crees']} nouvelle(s) alerte(s)");
            $this->info("Total : {$a['crees']} nouvelle(s) | {$a['mis_a_jour']} mise(s) à jour | {$a['regularisees']} régularisée(s)");
        }

        return self::SUCCESS;
    }
}
