<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\BaseRisque;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** « Lancer l'analyse » : mode automatique (réglages) ou manuel ciblant une année / un intervalle. */
class AnalyseModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $centre = Centre::create(['nom' => 'Centre analyse']);
        $fonction = Fonction::create(['nom' => 'Super admin analyse', 'role' => Role::Superadmin]);

        $user = User::create([
            'nom' => 'Super admin',
            'email' => 'analyse@example.invalid',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);
    }

    public function test_le_mode_automatique_suit_la_date_du_jour(): void
    {
        $res = $this->postJson('/api/defaillances/analyser', ['mode' => 'automatique'])
            ->assertOk()
            ->assertJsonPath('mode', 'automatique')
            ->assertJsonPath('annee_debut', null)
            ->assertJsonPath('annee_fin', null);

        $this->assertSame(now()->toDateString(), $res->json('date'));
        $this->assertStringContainsString('Analyse automatique', $res->json('message'));
    }

    public function test_le_mode_manuel_cible_une_annee_revolue(): void
    {
        $res = $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2023,
            'annee_fin' => 2023,
        ])->assertOk()
            ->assertJsonPath('mode', 'manuelle')
            ->assertJsonPath('annee_debut', 2023)
            ->assertJsonPath('annee_fin', 2023);

        // L'exercice 2023 n'est clos qu'après l'échéance IR 2024 + 48 h : l'analyse se place à cette date.
        $this->assertSame('2024-05-17', $res->json('date'));
        $this->assertStringContainsString('années 2023 à 2023', $res->json('message'));
    }

    public function test_le_mode_manuel_accepte_un_intervalle(): void
    {
        $res = $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2022,
            'annee_fin' => 2024,
        ])->assertOk();

        $this->assertSame('2025-05-17', $res->json('date'));
        $this->assertStringContainsString('années 2022 à 2024', $res->json('message'));
    }

    public function test_une_analyse_manuelle_cree_les_alertes_de_l_exercice_cible(): void
    {
        $contribuable = Contribuable::create(['nif' => '9000000001', 'nom' => 'Cible 2024', 'etat' => 1]);

        BaseRisque::create([
            'contribuable_id' => $contribuable->id,
            'source' => BaseRisque::IR,
            'annee_fiscale' => 2024,
            'mois' => 0,
            'ca' => 500_000_000,
            'resultat' => 15_000_000,
            'ir_a_payer' => 3_000_000,
        ]);
        BaseRisque::create([
            'contribuable_id' => $contribuable->id,
            'source' => BaseRisque::TVA,
            'annee_fiscale' => 2024,
            'mois' => 1,
            'ca' => 300_000_000,
            'ca_taxable' => 300_000_000,
        ]);

        $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2024,
            'annee_fin' => 2024,
        ])->assertOk();

        $alerte = Alerte::where('contribuable_id', $contribuable->id)->where('regle', 'r1')->first();
        $this->assertNotNull($alerte, "L'analyse de 2024 doit créer l'alerte R1 de 2024.");
        $this->assertSame(2024, (int) $alerte->annee_fiscale);
    }

    public function test_un_intervalle_inverse_ou_trop_long_est_refuse(): void
    {
        $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2025,
            'annee_fin' => 2023,
        ])->assertStatus(422)->assertJsonValidationErrors('annee_debut');

        $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2018,
            'annee_fin' => 2026,
        ])->assertStatus(422)->assertJsonValidationErrors('annee_debut');
    }

    public function test_le_mode_manuel_exige_les_annees(): void
    {
        $this->postJson('/api/defaillances/analyser', ['mode' => 'manuelle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('annee_debut');
    }
}
