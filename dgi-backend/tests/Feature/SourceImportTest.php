<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Services\DefaillanceDetector;
use App\Services\SourceDeclarationImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Import depuis la base source. La « source » est ici une base SQLite en mémoire (connexion « source_test ») :
 * le service n'utilise que le générateur de requêtes, donc ce test n'a pas besoin de PostgreSQL.
 */
class SourceImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.source_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'dgi.source.connection' => 'source_test',
            'dgi.source.table' => 'declarations_source',
        ]);
        DB::purge('source_test');

        Schema::connection('source_test')->create('declarations_source', function (Blueprint $t) {
            $t->id();
            $t->string('nif');
            $t->string('nom');
            $t->string('centre');
            $t->string('type_impot');
            $t->string('periode');
            $t->decimal('montant', 16, 2)->default(0);
            $t->date('date_depot')->nullable();
        });
    }

    /** @param array<string, mixed> $extra */
    private function source(string $nif, string $centre, string $impot, string $periode, $montant = 100, array $extra = []): void
    {
        DB::connection('source_test')->table('declarations_source')->insert($extra + [
            'nif' => $nif, 'nom' => "Contribuable {$nif}", 'centre' => $centre,
            'type_impot' => $impot, 'periode' => $periode, 'montant' => $montant, 'date_depot' => null,
        ]);
    }

    public function test_importe_les_lignes_valides_et_rejette_les_autres(): void
    {
        Centre::create(['nom' => 'Centre Nord']);

        $this->source('A1', 'Centre Nord', 'TVA', '2026-08');
        $this->source('A1', 'centre nord', 'tva', '2026-09');        // casse différente : acceptée
        $this->source('B1', 'Centre Inconnu', 'TVA', '2026-09');      // centre inconnu : rejetée
        $this->source('C1', 'Centre Nord', 'XYZ', '2026-09');         // impôt inconnu : rejetée
        $this->source('D1', 'Centre Nord', 'TVA', '2026-13');         // période invalide : rejetée
        $this->source('E1', 'Centre Nord', 'TVA', '2026-09', -5);     // montant négatif : rejetée

        $stats = app(SourceDeclarationImporter::class)->importer();

        $this->assertSame(6, $stats['lues']);
        $this->assertSame(2, $stats['importees']);
        $this->assertSame(4, $stats['rejetees']);
        $this->assertSame(1, $stats['contribuables_crees']);
        $this->assertCount(4, $stats['erreurs']);
        $this->assertSame(2, Declaration::count());
        $this->assertSame(['TVA'], Declaration::pluck('type_impot')->unique()->values()->all());
    }

    public function test_relancer_l_import_ne_cree_pas_de_doublons(): void
    {
        Centre::create(['nom' => 'Centre Nord']);
        $this->source('A1', 'Centre Nord', 'TVA', '2026-08', 100);

        $importer = app(SourceDeclarationImporter::class);
        $importer->importer();

        DB::connection('source_test')->table('declarations_source')->update(['montant' => 250]); // la source a changé
        $importer->importer();

        $this->assertSame(1, Contribuable::count());
        $this->assertSame(1, Declaration::count());
        $this->assertSame('250.00', Declaration::first()->montant);
    }

    public function test_filtres_par_periode_et_par_centre(): void
    {
        $nord = Centre::create(['nom' => 'Centre Nord']);
        Centre::create(['nom' => 'Centre Sud']);

        $this->source('A1', 'Centre Nord', 'TVA', '2026-06');
        $this->source('A1', 'Centre Nord', 'TVA', '2026-08');
        $this->source('A1', 'Centre Nord', 'TVA', '2026-10');
        $this->source('S1', 'Centre Sud', 'TVA', '2026-08');

        $importer = app(SourceDeclarationImporter::class);

        $stats = $importer->importer('2026-07', '2026-09');
        $this->assertSame(2, $stats['importees']); // 2026-08 Nord + 2026-08 Sud

        Declaration::query()->delete();
        $stats = $importer->importer(null, null, $nord->id);
        $this->assertSame(3, $stats['lues']);      // le centre Sud n'est même pas lu
        $this->assertSame(3, $stats['importees']);
    }

    public function test_periode_invalide_est_refusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SourceDeclarationImporter::class)->importer('2026-13');
    }

    public function test_donnees_importees_alimentent_la_detection_de_defaillance(): void
    {
        Centre::create(['nom' => 'Centre Nord']);

        // 8 mois déclarés (janvier à août 2026), puis rien en septembre et octobre
        foreach (range(1, 8) as $m) {
            $this->source('A1', 'Centre Nord', 'TVA', sprintf('2026-%02d', $m));
        }

        app(SourceDeclarationImporter::class)->importer();
        $stats = (new DefaillanceDetector())->analyser('2026-10');

        $this->assertSame(1, $stats['crees']);

        $alerte = Alerte::firstOrFail();
        $this->assertSame('2026-09', $alerte->periode);
        $this->assertSame(2, $alerte->mois_manques);
        $this->assertSame(8, $alerte->serie_precedente);
        $this->assertSame('moyen', $alerte->niveau);
    }

    public function test_tester_renvoie_le_nombre_de_lignes(): void
    {
        $this->source('A1', 'Centre Nord', 'TVA', '2026-08');
        $this->source('A2', 'Centre Nord', 'TVA', '2026-08');

        $info = app(SourceDeclarationImporter::class)->tester();

        $this->assertSame(2, $info['lignes']);
        $this->assertSame('declarations_source', $info['table']);
    }
}
