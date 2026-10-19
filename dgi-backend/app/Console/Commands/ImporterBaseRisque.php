<?php

namespace App\Console\Commands;

use App\Services\AnomalieAnalyseur;
use App\Services\ImportBaseRisque;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconstitue la base d'analyse risque depuis la base source, puis (option) lance l'analyse :
 *   php artisan dgi:importer-risque
 *   php artisan dgi:importer-risque --depuis=2024 --jusqua=2026 --analyser
 * (À lancer après dgi:importer-source : les contribuables doivent exister.)
 */
class ImporterBaseRisque extends Command
{
    protected $signature = 'dgi:importer-risque
        {--depuis= : Première année fiscale AAAA (défaut : tout l\'historique)}
        {--jusqua= : Dernière année fiscale AAAA}
        {--analyser : Lance ensuite l\'analyse de toutes les règles (dont R1, R2, R3, R5, R6, R7)}';

    protected $description = "Reconstitue la base d'analyse risque (IR, TVA, recoupement, DAU) depuis la base source (lecture seule)";

    public function handle(ImportBaseRisque $import, AnomalieAnalyseur $analyseur): int
    {
        try {
            $s = $import->importer($this->option('depuis') ?: null, $this->option('jusqua') ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error("Impossible de lire la base source : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("{$s['ecrites']} ligne(s) écrite(s) dans la base d'analyse risque ; {$s['nif_inconnus']} NIF inconnu(s) ignoré(s).");
        if ($s['nif_inconnus'] > 0) {
            $this->warn('NIF absents de la table des contribuables, par exemple : ' . implode(', ', $s['nif_exemples'])
                . ". Lancez d'abord php artisan dgi:importer-source (il crée les contribuables), puis relancez cette commande.");
        }
        foreach ($s['tables_absentes'] as $t) {
            $this->warn("Table « {$t} » introuvable dans la base source : ignorée (config/risque.php).");
        }

        if ($this->option('analyser')) {
            $a = $analyseur->analyser(AnomalieAnalyseur::aujourdhui());
            $r = $a['risque'] ?? null;
            if ($r === null) {
                $this->warn("AnomalieAnalyseur.php n'a pas été remplacé : les critères R ne sont pas lancés. Copiez la version fournie.");
            }
            $this->info("Analyse : {$a['crees']} nouvelle(s) alerte(s), {$a['mis_a_jour']} mise(s) à jour, {$a['regularisees']} régularisée(s)" . ($r !== null ? " (dont critères R : {$r['crees']} nouvelle(s))." : '.'));
        }

        return self::SUCCESS;
    }
}
