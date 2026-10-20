<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\Assujettissement;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Services\DefaillanceDetector;
use App\Services\SourceDeclarationImporter;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Import depuis la base source. La « source » est ici une base SQLite en mémoire (connexion « source_test »)
 * avec les MÊMES tables et colonnes que la vraie base DGI (voir config/dgi.php) :
 * info_contribuable, assujettissement, declaration_ir, declaration_tva.
 * Le service n'utilise que le générateur de requêtes, donc ce test n'a pas besoin de PostgreSQL.
 */
class SourceImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Même schéma que config/dgi.php (la casse des colonnes n'a pas d'importance pour le service)
        config([
            'database.connections.source_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'dgi.source.connection' => 'source_test',
            'dgi.source.alias_centres' => [],
            'dgi.source.declarations' => [
                'TVA' => [
                    'table' => 'declaration_tva', 'ligne_ca' => 170,
                    'colonnes' => [
                        'nif' => 'FISCAL_NO', 'annee' => 'ANNEE', 'mois' => 'MOIS', 'ligne' => 'IRD_LINE_NO',
                        'montant' => 'MONTANT_SOUMIS', 'montant_liquide' => null,
                        'date_limite' => 'DATE_ECHEANCE', 'date_depot' => 'DATE_RECEPTION', 'version' => null,
                    ],
                ],
                'IR' => [
                    'table' => 'declaration_ir', 'ligne_ca' => 15,
                    'colonnes' => [
                        'nif' => 'FISCAL_NO', 'annee' => 'ANNEE', 'mois' => 'MOIS', 'ligne' => 'IRD_LINE_NO',
                        'montant' => 'MONTANT_SOUMIS', 'montant_liquide' => 'MONTANT_LIQUIDE',
                        'date_limite' => 'DATE_ECHEANCE', 'date_depot' => 'DATE_RECEPTION', 'version' => 'VERSION_NO',
                    ],
                ],
            ],
            'dgi.source.assujettissements' => [
                'table' => 'assujettissement',
                'colonnes' => [
                    'nif' => 'NIF', 'annee' => 'ANNEE', 'debut' => 'DEBUT', 'fin' => 'FIN', 'periodicite' => 'PERIODICITE',
                    'impot' => 'ABREV', 'actif' => 'ACTIF', 'exonere' => 'EXONERE',
                ],
            ],
            'dgi.source.contribuables' => [
                'table' => 'info_contribuable',
                'colonnes' => [
                    'nif' => 'NIF', 'nom' => 'RAISON_SOCIALE', 'centre' => 'CENTRE_GEST', 'centre_code' => null,
                    'etat' => 'ETAT', 'date_cessation' => 'DATE_CESSATION', 'date_radiation' => 'DATE_RADIE', 'date_blocage' => 'DATE_BLOQUE',
                ],
            ],
            'dgi.source.centres' => [
                'table' => 'centre_gestionnaire',
                'colonnes' => ['code' => 'CENTRE ID', 'libelle' => 'LIBELLE CENTRE', 'abreviation' => 'CENTRE ABREV'],
            ],
        ]);
        DB::purge('source_test');

        Schema::connection('source_test')->create('info_contribuable', function (Blueprint $t) {
            $t->string('NIF', 20);
            $t->string('RAISON_SOCIALE')->nullable();
            $t->string('CENTRE_GEST')->nullable();
            $t->smallInteger('ETAT')->nullable();
            $t->date('DATE_CESSATION')->nullable();
            $t->date('DATE_RADIE')->nullable();
            $t->date('DATE_BLOQUE')->nullable();
        });

        Schema::connection('source_test')->create('assujettissement', function (Blueprint $t) {
            $t->string('NIF', 20);
            $t->smallInteger('ANNEE');
            $t->smallInteger('PERIODICITE')->nullable();
            $t->string('ABREV', 10);
            $t->smallInteger('ACTIF')->default(1);
            $t->smallInteger('EXONERE')->default(0);
            $t->date('DEBUT')->nullable();
            $t->date('FIN')->nullable();
        });

        // Une ligne par ligne du formulaire ; la ligne 170 porte le chiffre d'affaires
        Schema::connection('source_test')->create('declaration_tva', function (Blueprint $t) {
            $t->string('FISCAL_NO', 20);
            $t->smallInteger('ANNEE');
            $t->smallInteger('MOIS');
            $t->smallInteger('IRD_LINE_NO');
            $t->decimal('MONTANT_SOUMIS', 18, 2)->nullable();
            $t->date('DATE_ECHEANCE')->nullable();
            $t->date('DATE_RECEPTION')->nullable();
        });

        // IR : déclaration annuelle (mois vide ou 0) avec versions (déclarations rectificatives)
        Schema::connection('source_test')->create('declaration_ir', function (Blueprint $t) {
            $t->string('FISCAL_NO', 20);
            $t->smallInteger('ANNEE');
            $t->smallInteger('MOIS')->nullable();
            $t->smallInteger('IRD_LINE_NO');
            $t->decimal('MONTANT_SOUMIS', 18, 2)->nullable();
            $t->decimal('MONTANT_LIQUIDE', 18, 2)->nullable();
            $t->smallInteger('VERSION_NO')->default(1);
            $t->date('DATE_ECHEANCE')->nullable();
            $t->date('DATE_RECEPTION')->nullable();
        });
    }

    /** Contribuable de la table info_contribuable de la source. */
    private function sourceContribuable(string $nif, ?string $centre, string $nom = '', ?int $etat = 1): void
    {
        DB::connection('source_test')->table('info_contribuable')->insert([
            'NIF' => $nif,
            'RAISON_SOCIALE' => $nom !== '' ? $nom : "Contribuable {$nif}",
            'CENTRE_GEST' => $centre,
            'ETAT' => $etat,
        ]);
    }

    /** Ligne de déclaration TVA (la ligne 170 porte le chiffre d'affaires). */
    private function sourceTva(string $nif, int $annee, int $mois, ?float $ca, ?string $echeance = null, ?string $depot = null, int $ligne = 170): void
    {
        DB::connection('source_test')->table('declaration_tva')->insert([
            'FISCAL_NO' => $nif, 'ANNEE' => $annee, 'MOIS' => $mois, 'IRD_LINE_NO' => $ligne,
            'MONTANT_SOUMIS' => $ca, 'DATE_ECHEANCE' => $echeance, 'DATE_RECEPTION' => $depot,
        ]);
    }

    /** Ligne de déclaration IR (la ligne 15 porte le chiffre d'affaires). */
    private function sourceIr(string $nif, int $annee, ?int $mois, ?float $ca, int $version = 1, ?string $echeance = null, int $ligne = 15): void
    {
        DB::connection('source_test')->table('declaration_ir')->insert([
            'FISCAL_NO' => $nif, 'ANNEE' => $annee, 'MOIS' => $mois, 'IRD_LINE_NO' => $ligne,
            'MONTANT_SOUMIS' => $ca, 'MONTANT_LIQUIDE' => $ca, 'VERSION_NO' => $version, 'DATE_ECHEANCE' => $echeance,
        ]);
    }

    public function test_importe_les_lignes_valides_et_rejette_les_autres(): void
    {
        Centre::create(['nom' => 'Centre Nord']);

        $this->sourceContribuable('9100000001', 'Centre Nord', 'Alpha SARL');
        $this->sourceContribuable('9100000003', 'Centre Inconnu', 'Gamma SA');   // centre introuvable : créé sans centre
        $this->sourceTva('9100000001', 2026, 8, 100, '2026-09-15');              // valide
        $this->sourceTva('9100000001', 2026, 9, -5, '2026-10-15');               // CA négatif : rejetée
        $this->sourceTva('9100000002', 2026, 8, 50, '2026-09-15');               // absent de info_contribuable : créé sans centre
        $this->sourceTva('9100000003', 1850, 8, 100, '1851-09-15');              // année fiscale invalide : rejetée
        $this->sourceTva('9100000003', 2026, 13, 100, '2027-01-15');             // mois invalide : rejetée

        $stats = app(SourceDeclarationImporter::class)->importer();

        $this->assertSame(5, $stats['lues']);
        $this->assertSame(2, $stats['importees']);
        $this->assertSame(3, $stats['rejetees']);
        $this->assertCount(3, $stats['erreurs']);
        $this->assertSame(3, $stats['contribuables_crees']);
        $this->assertSame(2, $stats['sans_centre']);
        $this->assertSame(['Centre Inconnu' => 1], $stats['centres_non_resolus']);

        $this->assertSame(2, Declaration::count());
        $this->assertSame(['TVA'], Declaration::pluck('type_impot')->unique()->values()->all());

        $alpha = Contribuable::where('nif', '9100000001')->first();
        $this->assertSame('Alpha SARL', $alpha->nom);
        $this->assertSame('Centre Nord', $alpha->centre->nom);

        $declaration = Declaration::where('contribuable_id', $alpha->id)->first();
        $this->assertSame(2026, $declaration->annee_fiscale);
        $this->assertSame(8, $declaration->mois);
        $this->assertSame('100.00', $declaration->chiffre_affaires);
        $this->assertSame('2026-09-15', $declaration->date_limite->toDateString());
    }

    public function test_relancer_l_import_ne_cree_pas_de_doublons(): void
    {
        Centre::create(['nom' => 'Centre Nord']);
        $this->sourceContribuable('9100000001', 'Centre Nord');
        $this->sourceTva('9100000001', 2026, 8, 100, '2026-09-15');

        $importer = app(SourceDeclarationImporter::class);
        $importer->importer();

        DB::connection('source_test')->table('declaration_tva')->update(['MONTANT_SOUMIS' => 250]); // la source a changé
        $importer->importer();

        $this->assertSame(1, Contribuable::count());
        $this->assertSame(1, Declaration::count());
        $this->assertSame('250.00', Declaration::first()->chiffre_affaires);
    }

    public function test_filtres_par_annee_et_par_centre(): void
    {
        $nord = Centre::create(['nom' => 'Centre Nord']);
        Centre::create(['nom' => 'Centre Sud']);

        $this->sourceContribuable('9100000001', 'Centre Nord');
        $this->sourceContribuable('9100000002', 'Centre Sud');
        $this->sourceTva('9100000001', 2025, 12, 100, '2026-01-15');
        $this->sourceTva('9100000001', 2026, 1, 200, '2026-02-16');
        $this->sourceTva('9100000002', 2026, 2, 300, '2026-03-16');

        $importer = app(SourceDeclarationImporter::class);

        // Filtre par année : seules les déclarations de 2026 sont importées
        $stats = $importer->importer('2026');
        $this->assertSame(2, $stats['lues']);
        $this->assertSame(2, $stats['importees']);
        $this->assertSame(0, Declaration::where('annee_fiscale', 2025)->count());
        $this->assertSame(2, Declaration::count());

        // Filtre par centre (vue admin) : les contribuables du Sud sont ignorés
        Declaration::query()->delete();
        $stats = $importer->importer(null, null, $nord->id);
        $this->assertSame(3, $stats['lues']);       // les lignes du Sud sont lues mais écartées
        $this->assertSame(2, $stats['importees']);
        $this->assertSame(2, $stats['ignorees']);   // le contribuable du Sud + sa déclaration
        $this->assertSame(2, Declaration::count());
        $nifs = Declaration::join('contribuables', 'contribuables.id', '=', 'declarations.contribuable_id')
            ->pluck('nif')->unique()->values()->all();
        $this->assertSame(['9100000001'], $nifs);
    }

    public function test_annee_invalide_est_refusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Le filtre attend une année « AAAA » (ex. 2026), pas une période « AAAA-MM »
        app(SourceDeclarationImporter::class)->importer('2026-07');
    }

    public function test_derniere_version_dune_declaration_rectificative_est_retenue(): void
    {
        Centre::create(['nom' => 'Centre Nord']);
        $this->sourceContribuable('9100000001', 'Centre Nord');

        // IR 2026 (exercice annuel, mois 0) : la version 2 rectifie la version 1
        $this->sourceIr('9100000001', 2026, 0, 90, 1, '2026-04-30');
        $this->sourceIr('9100000001', 2026, 0, 100, 2, '2026-04-30');
        $this->sourceIr('9100000001', 2026, 0, 2, 2, '2026-04-30', 190); // autre ligne du formulaire : pas le CA

        $stats = app(SourceDeclarationImporter::class)->importer();

        $this->assertSame(1, $stats['lues']); // une seule déclaration après dédoublonnage par version
        $this->assertSame(1, Declaration::count());

        $declaration = Declaration::first();
        $this->assertSame(0, $declaration->mois);
        $this->assertSame('100.00', $declaration->chiffre_affaires);
    }

    public function test_tester_renvoie_les_tables_et_le_nombre_de_lignes(): void
    {
        $this->sourceTva('9100000001', 2026, 8, 100);
        $this->sourceTva('9100000001', 2026, 9, 100);
        $this->sourceIr('9100000001', 2026, 0, 500);

        $info = app(SourceDeclarationImporter::class)->tester();

        $this->assertSame(':memory:', $info['base']);
        $this->assertSame(3, $info['lignes']); // lignes des tables de déclarations seulement
        $this->assertStringContainsString('declaration_tva', $info['table']);
        $this->assertStringContainsString('declaration_ir', $info['table']);

        $noms = array_column($info['tables'], 'table');
        $this->assertContains('info_contribuable', $noms);
        $this->assertContains('assujettissement', $noms);
    }

    public function test_assujettissements_importes_et_filtres(): void
    {
        Centre::create(['nom' => 'Centre Nord']);
        $this->sourceContribuable('9100000001', 'Centre Nord');

        DB::connection('source_test')->table('assujettissement')->insert([
            ['NIF' => '9100000001', 'ANNEE' => 2026, 'PERIODICITE' => 1, 'ABREV' => 'TVA', 'ACTIF' => 1, 'EXONERE' => 0, 'DEBUT' => '2026-01-01', 'FIN' => null],
            ['NIF' => '9100000001', 'ANNEE' => 2026, 'PERIODICITE' => 5, 'ABREV' => 'IS', 'ACTIF' => 1, 'EXONERE' => 0, 'DEBUT' => null, 'FIN' => null],
            ['NIF' => '9100000001', 'ANNEE' => 2026, 'PERIODICITE' => 1, 'ABREV' => 'XYZ', 'ACTIF' => 1, 'EXONERE' => 0, 'DEBUT' => null, 'FIN' => null], // impôt inconnu
            ['NIF' => '9100000001', 'ANNEE' => 2026, 'PERIODICITE' => 9, 'ABREV' => 'TVA', 'ACTIF' => 1, 'EXONERE' => 0, 'DEBUT' => null, 'FIN' => null], // périodicité invalide
            ['NIF' => '8999999999', 'ANNEE' => 2026, 'PERIODICITE' => 1, 'ABREV' => 'TVA', 'ACTIF' => 1, 'EXONERE' => 0, 'DEBUT' => null, 'FIN' => null], // contribuable inconnu
        ]);

        $stats = app(SourceDeclarationImporter::class)->importer();

        $this->assertSame(2, $stats['assujettissements']);
        $this->assertSame(2, Assujettissement::count());

        $tva = Assujettissement::where('type_impot', 'TVA')->first();
        $this->assertSame(1, $tva->periodicite);
        $this->assertTrue($tva->actif);
        $this->assertFalse($tva->exonere);
        $this->assertSame('2026-01-01', $tva->date_debut->toDateString());
    }

    public function test_les_donnees_importees_alimentent_la_detection_de_defaillance(): void
    {
        Centre::create(['nom' => 'Centre Nord']);
        $this->sourceContribuable('9100000001', 'Centre Nord', 'Alpha SARL');
        $this->sourceContribuable('9100000002', 'Centre Nord', 'Beta SA');

        // Alpha déclare la TVA de janvier à août 2026, puis plus rien (septembre et octobre manquants).
        // Beta déclare septembre et octobre : leurs dates limites sont ainsi connues du détecteur.
        foreach (range(1, 8) as $m) {
            $this->sourceTva('9100000001', 2026, $m, 1000, sprintf('2026-%02d-15', $m + 1));
        }
        $this->sourceTva('9100000002', 2026, 9, 500, '2026-10-15');
        $this->sourceTva('9100000002', 2026, 10, 500, '2026-11-15');

        app(SourceDeclarationImporter::class)->importer();
        $stats = (new DefaillanceDetector())->analyser(new DateTimeImmutable('2026-11-20'));

        $this->assertSame(1, $stats['crees']);

        $alpha = Contribuable::where('nif', '9100000001')->first();
        $alerte = Alerte::firstOrFail();
        $this->assertSame($alpha->id, $alerte->contribuable_id);
        $this->assertSame(Alerte::REGLE_DEFAILLANCE, $alerte->regle);
        $this->assertSame('TVA', $alerte->type_impot);
        $this->assertSame(2026, $alerte->annee_fiscale);
        $this->assertSame(0, $alerte->mois);
        $this->assertSame(2, $alerte->periodes_manquees);      // septembre + octobre
        $this->assertSame(8, $alerte->periodes_declarees);
        $this->assertSame('2026-10-15', $alerte->date_limite->toDateString());
        $this->assertSame('faible', $alerte->niveau);          // 2 mois manquants : score R4 de 2/5
        $this->assertSame(Alerte::A_TRAITER, $alerte->statut);
        $this->assertSame([9, 10], $alerte->details['mois_manquants']);
        $this->assertSame('historique', $alerte->details['origine_obligation']); // obligation déduite des déclarations
    }
}
