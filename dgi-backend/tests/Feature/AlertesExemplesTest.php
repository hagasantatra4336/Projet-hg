<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use Database\Seeders\AlertesExemplesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les exemples de démonstration couvrent TOUTES les règles de détection, chacun sur un exercice différent,
 * et restent visibles dans la liste groupée des dossiers à traiter.
 */
class AlertesExemplesTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre exemples']);

        foreach (range(1, 8) as $i) {
            Contribuable::create([
                'nif' => '90000'.sprintf('%05d', $i),
                'nom' => 'Contribuable exemple '.$i,
                'centre_id' => $this->centre->id,
                'etat' => 1,
            ]);
        }

        $this->seed(AlertesExemplesSeeder::class);
    }

    private function superadmin(): User
    {
        $fonction = Fonction::create(['nom' => 'Superadmin exemples', 'role' => Role::Superadmin]);

        $user = User::create([
            'nom' => 'Superadmin exemples',
            'email' => 'super@exemples.test',
            'password' => 'motdepasse123',
            'centre_id' => $this->centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_le_seeder_cree_une_alerte_par_regle_sur_des_exercices_differents(): void
    {
        $this->assertDatabaseCount('alertes', 8);
        $this->assertEqualsCanonicalizing(Alerte::REGLES, Alerte::query()->pluck('regle')->all());

        $annees = Alerte::query()->pluck('annee_fiscale')->map(fn ($a) => (int) $a)->all();
        $this->assertSame(count($annees), count(array_unique($annees)), 'Chaque exemple doit porter sur un exercice différent.');
        $this->assertEqualsCanonicalizing([2019, 2020, 2021, 2022, 2023, 2024, 2025, 2026], $annees);
    }

    public function test_la_liste_des_dossiers_a_traiter_affiche_les_huit_regles(): void
    {
        $this->superadmin();

        $res = $this->getJson('/api/defaillances?grouper=1&statut='.Alerte::A_TRAITER);
        $res->assertOk()->assertJsonCount(8, 'data');

        $regles = collect($res->json('data'))->flatMap(fn ($d) => $d['regles'])->unique()->values()->all();
        $this->assertEqualsCanonicalizing(Alerte::REGLES, $regles);

        // Les badges de l'écran lisent score_risque/score_max (R1, R2, R3, R5, R6, R7) et score_r4 (R4).
        $scoreR4 = Alerte::where('regle', Alerte::REGLE_DEFAILLANCE)->first()->details['score_r4'] ?? null;
        $this->assertSame(4, $scoreR4);
        foreach (['r1', 'r2', 'r3', 'r5', 'r6', 'r7'] as $regle) {
            $details = Alerte::where('regle', $regle)->first()->details;
            $this->assertArrayHasKey('score_risque', $details, "Score manquant pour {$regle}.");
            $this->assertArrayHasKey('score_max', $details, "Score maximal manquant pour {$regle}.");
        }
    }

    public function test_le_filtre_par_exercice_isole_l_exemple_correspondant(): void
    {
        $this->superadmin();

        $res = $this->getJson('/api/defaillances?grouper=1&annee_fiscale=2023');
        $res->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('r2', $res->json('data.0.regles.0'));
    }
}
