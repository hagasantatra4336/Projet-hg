<?php

namespace App\Console\Commands;

use App\Services\SourceDeclarationImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importe les centres gestionnaires officiels (identifiant, libellé, abréviation) depuis la base source :
 *   php artisan dgi:importer-centres
 * À lancer AVANT le premier import des contribuables : c'est l'identifiant du centre qui permet de rattacher
 * chaque contribuable (et ses alertes) au bon centre. Un centre déjà créé à la main est complété (même nom).
 */
class ImporterCentres extends Command
{
    protected $signature = 'dgi:importer-centres';

    protected $description = 'Importe les centres gestionnaires officiels depuis la base source (lecture seule)';

    public function handle(SourceDeclarationImporter $importer): int
    {
        try {
            $stats = $importer->importerCentres();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error("Impossible de lire la base source : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("{$stats['lues']} centre(s) lu(s) : {$stats['crees']} créé(s), {$stats['mis_a_jour']} complété(s) avec leur code.");

        return self::SUCCESS;
    }
}
