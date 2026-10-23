<?php

namespace App\Console\Commands;

use App\Models\Centre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AJOUTE des contribuables et déclarations TVA simulés dans la base source de test, sans rien supprimer
 * (contrairement à dgi:source-test-db --reset). Chaque exécution crée de nouveaux NIF « NIF-AJT-00001 », « -00002 »…
 *
 *   php artisan dgi:source-test-data                         # 20 contribuables, 6 scénarios en rotation
 *   php artisan dgi:source-test-data --contribuables=100
 *   php artisan dgi:source-test-data --contribuables=5 --manques=3 --serie=9   # cas précis : 9 mois déclarés puis 3 manqués
 *   php artisan dgi:source-test-data --centre="Centre Nord"  # tous dans ce centre
 *
 * [CORRECTION] La commande écrit désormais dans les vraies tables source (info_contribuable + declaration_tva),
 * dont les noms et colonnes sont lus dans config/dgi.php (dgi.source.contribuables / dgi.source.declarations.TVA).
 * Avant, elle lisait l'ancienne config à table unique (dgi.source.table), qui n'existe plus.
 * Seule la TVA (mensuelle) est générée : pas d'IRSA dans la source actuelle.
 * Données fictives uniquement.
 */
class AjouterDonneesSourceTest extends Command
{
    protected $signature = 'dgi:source-test-data
        {--contribuables=20 : Nombre de contribuables à ajouter (1 à 500)}
        {--manques= : Mois manqués jusqu\'au mois de référence, pour TOUS les ajoutés (0 à 24)}
        {--serie= : Mois déclarés avant l\'interruption, pour TOUS les ajoutés (1 à 36)}
        {--centre= : Nom d\'un centre existant (défaut : répartition sur tous les centres)}';

    protected $description = 'Ajoute des déclarations TVA simulées dans la base source de test (sans rien supprimer)';

    public function handle(): int
    {
        $connexion = (string) config('dgi.source.connection');
        $tableContrib = (string) config('dgi.source.contribuables.table');
        $cc = (array) config('dgi.source.contribuables.colonnes');
        $tableTva = (string) config('dgi.source.declarations.TVA.table');
        $ct = (array) config('dgi.source.declarations.TVA.colonnes');
        $ligneCa = (int) config('dgi.source.declarations.TVA.ligne_ca', 170);

        $nombre = (int) $this->option('contribuables');
        if ($nombre < 1 || $nombre > 500) {
            $this->error('--contribuables doit être compris entre 1 et 500.');

            return self::FAILURE;
        }

        $manques = $this->entierOption('manques', 0, 24);
        $serie = $this->entierOption('serie', 1, 36);
        if ($manques === false || $serie === false) {
            $this->error('--manques doit être un entier de 0 à 24 et --serie un entier de 1 à 36.');

            return self::FAILURE;
        }
        if (($manques === null) !== ($serie === null)) {
            $this->error('Indiquez --manques et --serie ensemble (ou aucun des deux pour la rotation des 6 scénarios).');

            return self::FAILURE;
        }

        $centres = Centre::orderBy('id')->pluck('nom')->all();
        if ($this->option('centre') !== null) {
            $voulu = mb_strtolower(trim((string) $this->option('centre')));
            $centres = array_values(array_filter($centres, fn ($nom) => mb_strtolower((string) $nom) === $voulu));

            if ($centres === []) {
                $this->error('Centre « ' . $this->option('centre') . ' » introuvable dans l\'application.');

                return self::FAILURE;
            }
        }
        if ($centres === []) {
            $this->error("Créez d'abord au moins un centre dans l'application (Administration > Centres).");

            return self::FAILURE;
        }

        try {
            foreach ([$tableContrib, $tableTva] as $table) {
                if ($table === '' || ! Schema::connection($connexion)->hasTable($table)) {
                    $this->error("La table « {$table} » n'existe pas dans la connexion « {$connexion} » : vérifiez config/dgi.php.");

                    return self::FAILURE;
                }
            }

            $cx = DB::connection($connexion);
            $dernier = (string) $cx->table($tableContrib)->where($cc['nif'], 'like', 'NIF-AJT-%')->max($cc['nif']);
            $debut = preg_match('/(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

            [$contribuables, $declarations] = $this->generer($centres, $debut, $nombre, $manques, $serie, $cc, $ct, $ligneCa);

            $cx->transaction(function () use ($cx, $tableContrib, $tableTva, $contribuables, $declarations) {
                foreach (array_chunk($contribuables, 500) as $lot) {
                    $cx->table($tableContrib)->insertOrIgnore($lot);
                }
                foreach (array_chunk($declarations, 500) as $lot) {
                    $cx->table($tableTva)->insertOrIgnore($lot);
                }
            });
        } catch (Throwable $e) {
            $this->error("Échec : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('%d contribuable(s) ajouté(s) (%s à %s), %d ligne(s) de déclaration TVA.',
            $nombre, sprintf('NIF-AJT-%05d', $debut), sprintf('NIF-AJT-%05d', $debut + $nombre - 1), count($declarations)));
        $this->line('Pour les analyser :  php artisan dgi:importer-source --analyser');

        return self::SUCCESS;
    }

    /** @return int|null|false  null si l'option est absente, false si elle est invalide */
    private function entierOption(string $nom, int $min, int $max): int|null|false
    {
        $v = $this->option($nom);

        if ($v === null || $v === '') {
            return null;
        }

        return (is_numeric($v) && (int) $v == $v && (int) $v >= $min && (int) $v <= $max) ? (int) $v : false;
    }

    /**
     * @param array<int, string> $centres
     * @param array<string, string|null> $cc  colonnes de info_contribuable
     * @param array<string, string|null> $ct  colonnes de la table TVA
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function generer(array $centres, int $debut, int $nombre, ?int $manquesFixes, ?int $serieFixe, array $cc, array $ct, int $ligneCa): array
    {
        $ref = now()->startOfMonth()->subMonthNoOverflow(); // dernier mois attendu
        // [mois manqués, mois déclarés avant] : régulier | 1/8 | 2/7 | 4/10 | 3/3 (pas « habituel ») | 1/12 (très régulier)
        $scenarios = [[0, 12], [1, 8], [2, 7], [4, 10], [3, 3], [1, 12]];
        $contribuables = [];
        $declarations = [];

        for ($j = 0; $j < $nombre; $j++) {
            $numero = $debut + $j;
            $nif = sprintf('NIF-AJT-%05d', $numero);
            [$manques, $serie] = $manquesFixes !== null ? [$manquesFixes, $serieFixe] : $scenarios[$j % 6];
            $base = random_int(2_000_000, 40_000_000);

            $contribuables[] = [
                $cc['nif'] => $nif,
                $cc['nom'] => sprintf('Contribuable ajouté anonyme %05d', $numero),
                $cc['centre'] => $centres[$j % count($centres)],
                $cc['etat'] => 1,
            ];

            for ($k = $manques; $k < $manques + $serie; $k++) {
                $mois = $ref->copy()->subMonthsNoOverflow($k);
                $suivant = $mois->copy()->addMonthNoOverflow();

                $declarations[] = [
                    $ct['nif'] => $nif,
                    $ct['annee'] => $mois->year,
                    $ct['mois'] => $mois->month,
                    $ct['ligne'] => $ligneCa,
                    $ct['montant'] => round($base * random_int(85, 115) / 100, 2),
                    $ct['date_limite'] => $suivant->copy()->day(15)->toDateString(),
                    $ct['date_depot'] => $suivant->copy()->day(random_int(5, 15))->toDateString(),
                ];
            }
        }

        return [$contribuables, $declarations];
    }
}