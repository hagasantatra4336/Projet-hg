<?php

namespace App\Console\Commands;

use App\Models\Centre;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Crée (si besoin) la base PostgreSQL de TEST utilisée comme source, y crée la table et la remplit avec des
 * données SIMULÉES ET ANONYMISÉES (mêmes 6 scénarios que DeclarationDemoSeeder + quelques lignes invalides
 * pour vérifier les rejets) :
 *   php artisan dgi:source-test-db            # crée la base + la table + les données (relançable)
 *   php artisan dgi:source-test-db --reset    # supprime puis recrée la base de test
 *
 * Il faut au moins un centre dans l'application (Administration > Centres) : les contribuables de test y sont répartis.
 * Le compte PostgreSQL (SOURCE_DB_USERNAME) doit avoir le droit CREATEDB.
 * Par sécurité, --reset est refusé si le nom de la base ne contient pas « test » (sauf --force).
 */
class CreerBaseSourceTest extends Command
{
    protected $signature = 'dgi:source-test-db {--reset : Supprime puis recrée la base} {--force : Autorise --reset sur une base dont le nom ne contient pas « test »}';

    protected $description = 'Crée la base PostgreSQL de test (source) avec des déclarations simulées';

    public function handle(): int
    {
        $connexion = (string) config('dgi.source.connection');
        $cfg = config("database.connections.{$connexion}");

        if (! is_array($cfg) || ($cfg['driver'] ?? null) !== 'pgsql') {
            $this->error("La connexion « {$connexion} » doit être de type pgsql (voir config/database.php).");

            return self::FAILURE;
        }

        $base = (string) $cfg['database'];

        if (! preg_match('/^[A-Za-z0-9_]+$/', $base)) {
            $this->error("Nom de base invalide « {$base} » : lettres, chiffres et _ uniquement.");

            return self::FAILURE;
        }

        $centres = Centre::orderBy('id')->pluck('nom')->all();
        if ($centres === []) {
            $this->error("Créez d'abord au moins un centre dans l'application (Administration > Centres).");

            return self::FAILURE;
        }

        if ($this->option('reset') && ! $this->option('force') && ! str_contains(strtolower($base), 'test')) {
            $this->error("Refus de supprimer « {$base} » : son nom ne contient pas « test ». Ajoutez --force si c'est voulu.");

            return self::FAILURE;
        }

        try {
            $this->creerBase($cfg, $base, (bool) $this->option('reset'));
            $total = $this->remplir($connexion, $centres);
        } catch (Throwable $e) {
            $this->error("Échec : {$e->getMessage()}");
            $this->line('Vérifiez que PostgreSQL tourne, SOURCE_DB_* dans le .env, et que le compte a le droit CREATEDB.');

            return self::FAILURE;
        }

        $this->info("Base « {$base} » prête : {$total} ligne(s) dans « " . config('dgi.source.table') . ' ».');
        $this->line('Étapes suivantes :  php artisan dgi:importer-source --tester   puis   php artisan dgi:importer-source --analyser');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $cfg */
    private function creerBase(array $cfg, string $base, bool $reset): void
    {
        // Connexion d'administration : on se connecte à la base « postgres » pour pouvoir créer/supprimer la nôtre.
        config(['database.connections.source_admin' => array_merge($cfg, ['database' => 'postgres', 'url' => null])]);
        DB::purge('source_admin');
        $admin = DB::connection('source_admin');

        $existe = $admin->selectOne('select 1 as ok from pg_database where datname = ?', [$base]) !== null;

        if ($existe && $reset) {
            DB::purge((string) config('dgi.source.connection')); // ferme nos propres connexions à cette base
            $admin->statement('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$base]);
            $admin->statement("drop database \"{$base}\"");
            $this->line("Base « {$base} » supprimée.");
            $existe = false;
        }

        if (! $existe) {
            $admin->statement("create database \"{$base}\" encoding 'UTF8' template template0");
            $this->line("Base « {$base} » créée.");
        } else {
            $this->line("Base « {$base} » déjà présente : la table est recréée avec des données neuves.");
        }

        DB::purge('source_admin');
    }

    /**
     * @param array<int, string> $centres noms des centres de l'application
     */
    private function remplir(string $connexion, array $centres): int
    {
        $table = (string) config('dgi.source.table');
        $c = (array) config('dgi.source.colonnes');
        $schema = Schema::connection($connexion);

        $schema->dropIfExists($table);
        $schema->create($table, function (Blueprint $t) use ($c) {
            $t->id();
            $t->string($c['nif'], 30);
            $t->string($c['nom']);
            $t->string($c['centre']);
            $t->string($c['type_impot'], 10);
            $t->char($c['periode'], 7);
            $t->decimal($c['montant'], 16, 2)->default(0);
            $t->date($c['date_depot'])->nullable();
            $t->unique([$c['nif'], $c['type_impot'], $c['periode']]);
            $t->index($c['periode']);
        });

        mt_srand(42); // résultats reproductibles

        $ref = now()->startOfMonth()->subMonthNoOverflow(); // dernier mois attendu
        // [mois manqués, mois déclarés avant] : régulier | 1/8 | 2/7 | 4/10 | 3/3 (pas « habituel ») | 1/12 (très régulier)
        $scenarios = [[0, 12], [1, 8], [2, 7], [4, 10], [3, 3], [1, 12]];
        $lignes = [];

        $ligne = function (string $nif, string $nom, string $centre, string $impot, string $periode, $montant, ?string $depot) use ($c) {
            return [
                $c['nif'] => $nif, $c['nom'] => $nom, $c['centre'] => $centre, $c['type_impot'] => $impot,
                $c['periode'] => $periode, $c['montant'] => $montant, $c['date_depot'] => $depot,
            ];
        };

        for ($i = 1; $i <= 30; $i++) {
            $nif = sprintf('NIF-SRC-%03d', $i);
            $nom = sprintf('Contribuable source anonyme %03d', $i);
            $centre = $centres[($i - 1) % count($centres)];
            [$manques, $serie] = $scenarios[($i - 1) % 6];
            $base = mt_rand(2_000_000, 40_000_000);

            for ($k = $manques; $k < $manques + $serie; $k++) {
                $mois = $ref->copy()->subMonthsNoOverflow($k);
                $lignes[] = $ligne($nif, $nom, $centre, 'TVA', $mois->format('Y-m'), round($base * mt_rand(85, 115) / 100, 2),
                    $mois->copy()->addMonthNoOverflow()->day(mt_rand(5, 15))->toDateString());
            }

            if ($i % 3 === 0) {
                for ($k = 0; $k < 12; $k++) {
                    $mois = $ref->copy()->subMonthsNoOverflow($k);
                    $lignes[] = $ligne($nif, $nom, $centre, 'IRSA', $mois->format('Y-m'), round(($base / 4) * mt_rand(85, 115) / 100, 2),
                        $mois->copy()->addMonthNoOverflow()->day(mt_rand(5, 15))->toDateString());
                }
            }
        }

        // Lignes volontairement invalides : elles doivent être REJETÉES à l'import (et signalées), sans bloquer le reste.
        $m = $ref->format('Y-m');
        $lignes[] = $ligne('NIF-SRC-900', 'Rejet : centre inconnu', 'Centre qui n\'existe pas', 'TVA', $m, 1000, null);
        $lignes[] = $ligne('NIF-SRC-901', 'Rejet : impôt inconnu', $centres[0], 'XYZ', $m, 1000, null);
        $lignes[] = $ligne('NIF-SRC-902', 'Rejet : période invalide', $centres[0], 'TVA', '2026-13', 1000, null);
        $lignes[] = $ligne('NIF-SRC-903', 'Rejet : montant négatif', $centres[0], 'TVA', $m, -5, null);

        $cx = DB::connection($connexion);
        foreach (array_chunk($lignes, 500) as $lot) {
            $cx->table($table)->insert($lot);
        }

        return count($lignes);
    }
}
