<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Assujettissement;
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

    public function test_une_analyse_manuelle_ne_cree_aucune_alerte_hors_de_l_exercice_cible(): void
    {
        $contribuable = Contribuable::create(['nif' => '9000000002', 'nom' => 'Cible 2024 uniquement', 'etat' => 1]);

        // TVA mensuelle assujettie en 2023 et 2024, aucune déclaration : les deux exercices sont en défaut,
        // mais seule l'année demandée doit être jugée.
        foreach ([2023, 2024] as $annee) {
            Assujettissement::create([
                'contribuable_id' => $contribuable->id,
                'type_impot' => 'TVA',
                'annee_fiscale' => $annee,
                'periodicite' => 1,
                'actif' => true,
                'exonere' => false,
            ]);
        }

        $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2024,
            'annee_fin' => 2024,
        ])->assertOk();

        $annees = Alerte::where('contribuable_id', $contribuable->id)
            ->pluck('annee_fiscale')->map(fn ($a) => (int) $a)->unique()->values()->all();

        $this->assertSame([2024], $annees, 'Seules les alertes de l’exercice 2024 doivent être créées.');
        $this->assertSame(0, Alerte::where('contribuable_id', $contribuable->id)->where('annee_fiscale', 2023)->count());
        $this->assertTrue(Alerte::where('contribuable_id', $contribuable->id)
            ->where('regle', Alerte::REGLE_DEFAILLANCE)->where('annee_fiscale', 2024)->exists());
    }

    public function test_la_liste_filtree_sur_un_intervalle_ne_montre_que_ses_exercices(): void
    {
        $contribuable = Contribuable::create(['nif' => '9000000003', 'nom' => 'Intervalle 2023-2024', 'etat' => 1]);

        foreach ([2022, 2023, 2024] as $annee) {
            Assujettissement::create([
                'contribuable_id' => $contribuable->id,
                'type_impot' => 'TVA',
                'annee_fiscale' => $annee,
                'periodicite' => 1,
                'actif' => true,
                'exonere' => false,
            ]);
        }

        $this->postJson('/api/defaillances/analyser', [
            'mode' => 'manuelle',
            'annee_debut' => 2023,
            'annee_fin' => 2024,
        ])->assertOk();

        $res = $this->getJson('/api/defaillances?grouper=1&annee_min=2023&annee_max=2024&search=9000000003')
            ->assertOk();

        $this->assertSame(1, $res->json('total'));
        $annees = collect($res->json('data.0.alertes'))->pluck('annee_fiscale')->map(fn ($a) => (int) $a)->unique()->sort()->values()->all();
        $this->assertSame([2023, 2024], $annees);
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
