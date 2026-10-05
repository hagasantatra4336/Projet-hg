<?php

namespace App\Console\Commands;

use App\Models\Centre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AJOUTE des contribuables et déclarations simulés dans la base source de test, sans rien supprimer
 * (contrairement à dgi:source-test-db --reset). Chaque exécution crée de nouveaux NIF « NIF-AJT-00001 », « -00002 »…
 *
 *   php artisan dgi:source-test-data                         # 20 contribuables, 6 scénarios en rotation
 *   php artisan dgi:source-test-data --contribuables=100
 *   php artisan dgi:source-test-data --contribuables=5 --manques=3 --serie=9   # cas précis : 9 mois déclarés puis 3 manqués
 *   php artisan dgi:source-test-data --centre="Centre Nord"  # tous dans ce centre
 *
 * La table doit exister (créée par php artisan dgi:source-test-db). Données fictives uniquement.
 */
class AjouterDonneesSourceTest extends Command
{
    protected $signature = 'dgi:source-test-data
        {--contribuables=20 : Nombre de contribuables à ajouter (1 à 500)}
        {--manques= : Mois manqués jusqu\'au mois de référence, pour TOUS les ajoutés (0 à 24)}
        {--serie= : Mois déclarés avant l\'interruption, pour TOUS les ajoutés (1 à 36)}
        {--centre= : Nom d\'un centre existant (défaut : répartition sur tous les centres)}
        {--sans-irsa : Ne génère pas d\'IRSA (TVA seulement)}';

    protected $description = 'Ajoute des déclarations simulées dans la base source de test (sans rien supprimer)';

    public function handle(): int
    {
        $connexion = (string) config('dgi.source.connection');
        $table = (string) config('dgi.source.table');
        $c = (array) config('dgi.source.colonnes');

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
            if (! Schema::connection($connexion)->hasTable($table)) {
                $this->error("La table « {$table} » n'existe pas : lancez d'abord  php artisan dgi:source-test-db");

                return self::FAILURE;
            }

            $cx = DB::connection($connexion);
            $dernier = (string) $cx->table($table)->where($c['nif'], 'like', 'NIF-AJT-%')->max($c['nif']);
            $debut = preg_match('/(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

            $lignes = $this->generer($centres, $debut, $nombre, $manques, $serie, ! $this->option('sans-irsa'), $c);

            DB::connection($connexion)->transaction(function () use ($cx, $table, $lignes) {
                foreach (array_chunk($lignes, 500) as $lot) {
                    $cx->table($table)->insertOrIgnore($lot);
                }
            });
        } catch (Throwable $e) {
            $this->error("Échec : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('%d contribuable(s) ajouté(s) (%s à %s), %d ligne(s) de déclaration.',
            $nombre, sprintf('NIF-AJT-%05d', $debut), sprintf('NIF-AJT-%05d', $debut + $nombre - 1), count($lignes)));
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
     * @param array<string, string> $c  mapping des colonnes
     *
     * @return array<int, array<string, mixed>>
     */
    private function generer(array $centres, int $debut, int $nombre, ?int $manquesFixes, ?int $serieFixe, bool $irsa, array $c): array
    {
        $ref = now()->startOfMonth()->subMonthNoOverflow(); // dernier mois attendu
        // [mois manqués, mois déclarés avant] : régulier | 1/8 | 2/7 | 4/10 | 3/3 (pas « habituel ») | 1/12 (très régulier)
        $scenarios = [[0, 12], [1, 8], [2, 7], [4, 10], [3, 3], [1, 12]];
        $lignes = [];

        $ligne = fn (string $nif, string $nom, string $centre, string $impot, string $periode, $montant, ?string $depot) => [
            $c['nif'] => $nif, $c['nom'] => $nom, $c['centre'] => $centre, $c['type_impot'] => $impot,
            $c['periode'] => $periode, $c['montant'] => $montant, $c['date_depot'] => $depot,
        ];

        for ($j = 0; $j < $nombre; $j++) {
            $numero = $debut + $j;
            $nif = sprintf('NIF-AJT-%05d', $numero);
            $nom = sprintf('Contribuable ajouté anonyme %05d', $numero);
            $centre = $centres[$j % count($centres)];
            [$manques, $serie] = $manquesFixes !== null ? [$manquesFixes, $serieFixe] : $scenarios[$j % 6];
            $base = random_int(2_000_000, 40_000_000);

            for ($k = $manques; $k < $manques + $serie; $k++) {
                $mois = $ref->copy()->subMonthsNoOverflow($k);
                $lignes[] = $ligne($nif, $nom, $centre, 'TVA', $mois->format('Y-m'), round($base * random_int(85, 115) / 100, 2),
                    $mois->copy()->addMonthNoOverflow()->day(random_int(5, 15))->toDateString());
            }

            if ($irsa && $j % 3 === 2) {
                for ($k = 0; $k < 12; $k++) {
                    $mois = $ref->copy()->subMonthsNoOverflow($k);
                    $lignes[] = $ligne($nif, $nom, $centre, 'IRSA', $mois->format('Y-m'), round(($base / 4) * random_int(85, 115) / 100, 2),
                        $mois->copy()->addMonthNoOverflow()->day(random_int(5, 15))->toDateString());
                }
            }
        }

        return $lignes;
    }
}
