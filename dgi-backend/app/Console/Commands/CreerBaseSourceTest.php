<?php

namespace App\Console\Commands;

use App\Models\Centre;
use Database\Seeders\DeclarationDemoSeeder;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Crée (si besoin) la base PostgreSQL de TEST utilisée comme source, avec les MÊMES tables et colonnes que la vraie base
 * de la DGI (exemples fournis) et des données SIMULÉES ET ANONYMISÉES :
 *   declaration_ir, declaration_tva (une ligne par ligne du formulaire, CA = ligne 15 / 170, DATE_ECHEANCE, versions),
 *   assujettissement (impôt, année, périodicité), info_contribuable (état, centre), centre_gestionnaire (identifiants).
 * Mêmes scénarios que DeclarationDemoSeeder (défaillances, baisses de CA, cessation, radiation, exonération...)
 * + quelques lignes invalides pour vérifier les rejets :
 *   php artisan dgi:source-test-db            # crée la base + les tables + les données (relançable)
 *   php artisan dgi:source-test-db --reset    # supprime puis recrée la base de test
 *
 * Il faut au moins un centre dans l'application (Administration > Centres) : les contribuables de test y sont répartis.
 * Le compte PostgreSQL (SOURCE_DB_USERNAME) doit avoir le droit CREATEDB.
 * Par sécurité, si le nom de la base ne contient pas « test », la commande est refusée (sauf --force) dès que ces tables
 * contiennent déjà des données, ou avec --reset : elle SUPPRIME puis recrée les tables source, il ne faut jamais la lancer
 * sur une vraie base de déclarations. Une base vide (ex. « declarations_source » tout juste créée) est acceptée.
 */
class CreerBaseSourceTest extends Command
{
    /** Tables créées (et recréées) dans la base source de test. */
    private const TABLES = ['declaration_ir', 'declaration_tva', 'assujettissement', 'info_contribuable', 'centre_gestionnaire'];

    protected $signature = 'dgi:source-test-db {--reset : Supprime puis recrée la base} {--force : Autorise --reset sur une base dont le nom ne contient pas « test »}';

    protected $description = 'Crée la base PostgreSQL de test (source) avec des déclarations IR/TVA simulées';

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

        // Sécurité : cette commande SUPPRIME puis recrée les 5 tables source (et la base avec --reset).
        // Sur une base dont le nom ne contient pas « test », on ne refuse que s'il y a quelque chose à détruire :
        //   - --reset (suppression de toute la base) : toujours refusé sans --force ;
        //   - sinon : refusé seulement si l'une de ces tables existe déjà et contient des lignes (vraies déclarations ?).
        // Une base vide, ou sans ces tables, peut donc être remplie sans --force.
        if (! $this->option('force') && ! str_contains(strtolower($base), 'test')) {
            $existantes = $this->option('reset') ? [] : $this->tablesAvecDonnees($connexion);

            if ($this->option('reset') || $existantes !== []) {
                $this->error("Refus : la base « {$base} » ne contient pas « test » dans son nom, et cette commande "
                    . ($this->option('reset')
                        ? 'supprimerait toute la base.'
                        : 'supprime puis recrée des tables qui contiennent déjà des données : ' . implode(', ', $existantes) . '.'));
                $this->line('Si c\'est bien une base de TEST, ajoutez --force. Sinon utilisez une base dédiée (ex. SOURCE_DB_DATABASE=dgi_source_test).');

                return self::FAILURE;
            }
        }

        try {
            $this->creerBase($cfg, $base, (bool) $this->option('reset'));
            $total = $this->remplir($connexion, $centres);
        } catch (Throwable $e) {
            $this->error("Échec : {$e->getMessage()}");
            $this->line('Vérifiez que PostgreSQL tourne, SOURCE_DB_* dans le .env, et que le compte a le droit CREATEDB.');

            return self::FAILURE;
        }

        $this->info("Base « {$base} » prête : {$total} ligne(s) de déclaration dans les tables de test.");
        $this->line('Étapes suivantes :');
        $this->line('  php artisan dgi:importer-centres');
        $this->line('  php artisan dgi:importer-source --tester');
        $this->line('  php artisan dgi:importer-source --analyser');

        return self::SUCCESS;
    }

    /**
     * Tables de test déjà présentes ET non vides dans la base source (base inexistante ou inaccessible : aucune).
     *
     * @return array<int, string>
     */
    private function tablesAvecDonnees(string $connexion): array
    {
        $trouvees = [];

        try {
            $schema = Schema::connection($connexion);
            foreach (self::TABLES as $table) {
                if ($schema->hasTable($table) && DB::connection($connexion)->table($table)->exists()) {
                    $trouvees[] = $table;
                }
            }
        } catch (Throwable) {
            return []; // base absente : rien à détruire
        }

        return $trouvees;
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
        $cx = DB::connection($connexion);
        $schema = Schema::connection($connexion);
        foreach (self::TABLES as $t) {
            $schema->dropIfExists($t);
        }

        // Centres gestionnaires : identifiant officiel, libellé, abréviation (d'après les centres de l'application)
        $schema->create('centre_gestionnaire', function (Blueprint $t) {
            $t->string('CENTRE ID', 20);
            $t->string('LIBELLE CENTRE');
            $t->string('CENTRE ABREV');
        });

        $codes = [];
        $lignesCentres = [];
        foreach (Centre::orderBy('id')->get() as $i => $c) {
            $codes[$i] = $c->code ?: sprintf('21-9-%03d', $c->id);
            $lignesCentres[] = ['CENTRE ID' => $codes[$i], 'LIBELLE CENTRE' => $c->nom, 'CENTRE ABREV' => $c->abreviation ?: $c->nom];
        }
        $cx->table('centre_gestionnaire')->insert($lignesCentres);

        // Contribuables
        $schema->create('info_contribuable', function (Blueprint $t) {
            $t->string('NIF', 10);
            $t->smallInteger('ETAT');
            $t->string('RAISON_SOCIALE');
            $t->string('CENTRE_GEST')->nullable();
            $t->date('DATE_CESSATION')->nullable();
            $t->date('DATE_RADIE')->nullable();
            $t->date('DATE_BLOQUE')->nullable();
        });

        // Assujettissements
        $schema->create('assujettissement', function (Blueprint $t) {
            $t->string('NIF', 10);
            $t->string('REGIST_NAME')->nullable();
            $t->smallInteger('ANNEE');
            $t->date('DEBUT')->nullable();
            $t->date('FIN')->nullable();
            $t->smallInteger('PERIODICITE');
            $t->date('DATE_ASSUJ')->nullable();
            $t->string('ABREV', 10);
            $t->smallInteger('ACTIF');
            $t->smallInteger('EXONERE');
        });

        // Déclarations : une ligne par ligne du formulaire
        $schema->create('declaration_ir', function (Blueprint $t) {
            $t->string('CF')->nullable();
            $t->string('FISCAL_NO', 10);
            $t->string('RS')->nullable();
            $t->integer('RECEPTION_DATE')->nullable();
            $t->integer('TAX_PERIOD_NO')->nullable();
            $t->smallInteger('MOIS')->nullable();
            $t->smallInteger('ANNEE');
            $t->decimal('MONTANT_SOUMIS', 18, 2)->nullable();
            $t->decimal('MONTANT_LIQUIDE', 18, 2)->nullable();
            $t->smallInteger('VERSION_NO');
            $t->smallInteger('IRD_LINE_NO');
            $t->string('LABEL_2')->nullable();
            $t->date('DATE_RECEPTION')->nullable();
            $t->date('DATE_ECHEANCE')->nullable();
        });
        $schema->create('declaration_tva', function (Blueprint $t) {
            $t->string('CENTRE')->nullable();
            $t->string('FISCAL_NO', 10);
            $t->string('RS')->nullable();
            $t->integer('RECEPTION_DATE')->nullable();
            $t->integer('TAX_PERIOD_NO')->nullable();
            $t->smallInteger('MOIS');
            $t->smallInteger('ANNEE');
            $t->decimal('MONTANT_SOUMIS', 18, 2)->nullable();
            $t->decimal('MONTANT_LIQUIDE', 18, 2)->nullable();
            $t->smallInteger('IRD_LINE_NO');
            $t->string('LABEL_2')->nullable();
            $t->date('DATE_RECEPTION')->nullable();
            $t->date('DATE_ECHEANCE')->nullable();
        });

        // Données simulées (mêmes scénarios que le seeder)
        $jeu = DeclarationDemoSeeder::jeu('91000', 'Contribuable source anonyme ', new DateTimeImmutable(now()->toDateString()));
        // Répartition mélangée (et non $i % n) : sinon, avec 2 centres, tous les scénarios identiques tombent dans le même centre
        $centreDe = fn (int $i) => $centres[($i + intdiv($i, 12)) % count($centres)];

        $contribuables = [];
        $nomDe = [];
        $centreNif = [];
        foreach ($jeu['contribuables'] as $i => $c) {
            $nomDe[$c['nif']] = $c['nom'];
            $centreNif[$c['nif']] = $centreDe($i);
            $contribuables[] = [
                'NIF' => $c['nif'], 'ETAT' => $c['etat'], 'RAISON_SOCIALE' => $c['nom'], 'CENTRE_GEST' => $centreDe($i),
                'DATE_CESSATION' => $c['date_cessation'], 'DATE_RADIE' => $c['date_radiation'], 'DATE_BLOQUE' => $c['date_blocage'],
            ];
        }
        foreach (array_chunk($contribuables, 500) as $lot) {
            $cx->table('info_contribuable')->insert($lot);
        }

        $assujs = array_map(fn ($a) => [
            'NIF' => $a['nif'], 'REGIST_NAME' => $nomDe[$a['nif']], 'ANNEE' => $a['annee_fiscale'], 'DEBUT' => $a['date_debut'], 'FIN' => $a['date_fin'],
            'PERIODICITE' => $a['periodicite'], 'DATE_ASSUJ' => $a['date_debut'], 'ABREV' => $a['type_impot'],
            'ACTIF' => $a['actif'] ? 1 : 0, 'EXONERE' => $a['exonere'] ? 1 : 0,
        ], $jeu['assujettissements']);
        foreach (array_chunk($assujs, 500) as $lot) {
            $cx->table('assujettissement')->insert($lot);
        }

        // Lignes de formulaire : CA (IR ligne 15, TVA ligne 170) + quelques autres lignes
        $lignesIr = [];
        $lignesTva = [];
        $periode = 1000;
        foreach ($jeu['declarations'] as $d) {
            $periode++;
            $commun = [
                'FISCAL_NO' => $d['nif'], 'RS' => $nomDe[$d['nif']], 'RECEPTION_DATE' => $d['annee_fiscale'], 'TAX_PERIOD_NO' => $periode,
                'ANNEE' => $d['annee_fiscale'], 'DATE_RECEPTION' => $d['date_depot'], 'DATE_ECHEANCE' => $d['date_limite'],
            ];
            $ca = $d['chiffre_affaires'];

            if ($d['type_impot'] === 'IR') {
                // Une déclaration rectificative (version 1, CA différent) puis la version définitive (version 2) pour les exercices pairs
                $versions = $d['annee_fiscale'] % 2 === 0 ? [[1, round($ca * 0.9, 2)], [2, $ca]] : [[2, $ca]];
                foreach ($versions as [$version, $caVersion]) {
                    foreach ([[15, 'Chiffre d\'affaires total (L12 + L13)', $caVersion], [20, 'Résultat comptable avant impôt', round($caVersion * 0.1, 2)], [115, 'IR brut', round($caVersion * 0.02, 2)], [190, 'IR net à payer', round($caVersion * 0.02, 2)]] as [$ligne, $libelle, $montant]) {
                        $lignesIr[] = $commun + ['CF' => $centreNif[$d['nif']], 'MOIS' => null, 'MONTANT_SOUMIS' => $ligne === 190 ? null : $montant, 'MONTANT_LIQUIDE' => $montant,
                            'VERSION_NO' => $version, 'IRD_LINE_NO' => $ligne, 'LABEL_2' => $libelle];
                    }
                }
            } else {
                foreach ([[150, 'Chiffre d\'affaires taxable', $ca], [160, 'Chiffre d\'affaires exonéré', 0], [170, 'Chiffre d\'affaires total (lignes 150 + 160)', $ca], [400, 'Crédit de TVA', 0]] as [$ligne, $libelle, $montant]) {
                    $lignesTva[] = $commun + ['CENTRE' => $centreNif[$d['nif']], 'MOIS' => $d['mois'], 'MONTANT_SOUMIS' => $montant, 'MONTANT_LIQUIDE' => $montant,
                        'IRD_LINE_NO' => $ligne, 'LABEL_2' => $libelle];
                }
            }
        }

        // Lignes volontairement invalides : elles doivent être REJETÉES à l'import (et signalées), sans bloquer le reste.
        $annee = (int) now()->year;
        $invalide = fn (string $nif, int $an, int $mois, float $ca) => [
            'CENTRE' => null, 'FISCAL_NO' => $nif, 'RS' => 'Rejet', 'RECEPTION_DATE' => $an, 'TAX_PERIOD_NO' => 9999, 'MOIS' => $mois, 'ANNEE' => $an,
            'MONTANT_SOUMIS' => $ca, 'MONTANT_LIQUIDE' => $ca, 'IRD_LINE_NO' => 170, 'LABEL_2' => 'CA', 'DATE_RECEPTION' => null, 'DATE_ECHEANCE' => now()->subDays(30)->toDateString(),
        ];
        $lignesTva[] = $invalide('9100099901', 1850, 3, 1000);                 // année fiscale invalide
        $lignesTva[] = $invalide('9100099902', $annee - 1, 13, 1000);          // mois invalide
        $lignesTva[] = $invalide('9100099903', $annee - 1, 3, -5);             // chiffre d'affaires négatif
        // Une déclaration valide d'un contribuable absent de la table des contribuables : créé sans centre (avertissement)
        $lignesTva[] = ['DATE_ECHEANCE' => now()->subMonths(2)->toDateString()] + $invalide('9100099904', $annee - 1, 6, 5000000);

        foreach (array_chunk($lignesIr, 500) as $lot) {
            $cx->table('declaration_ir')->insert($lot);
        }
        foreach (array_chunk($lignesTva, 500) as $lot) {
            $cx->table('declaration_tva')->insert($lot);
        }

        return count($lignesIr) + count($lignesTva);
    }
}