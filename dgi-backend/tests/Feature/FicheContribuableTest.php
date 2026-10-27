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

/** Fiche de résultat contribuable : synthèse, coordonnées, observations, export PDF et accès par centre. */
class FicheContribuableTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Centre $autreCentre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre fiche A']);
        $this->autreCentre = Centre::create(['nom' => 'Centre fiche B']);
    }

    private function utilisateur(Role $role, ?Centre $centre = null): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction ' . $role->value, 'role' => $role]);

        return User::create([
            'nom' => 'Utilisateur ' . $role->value,
            'email' => $role->value . '@fiche.test',
            'password' => 'motdepasse123',
            'centre_id' => ($centre ?? $this->centre)->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    private function contribuable(Centre $centre, string $nif): Contribuable
    {
        return Contribuable::create([
            'nif' => $nif,
            'nom' => 'Société ' . $nif,
            'centre_id' => $centre->id,
            'etat' => 1,
        ]);
    }

    public function test_la_fiche_expose_la_synthese_les_chiffres_et_les_coordonnees(): void
    {
        Sanctum::actingAs($this->utilisateur(Role::Superadmin));

        $c = $this->contribuable($this->centre, '1111111111');
        $c->update([
            'telephone' => '034 12 345 67',
            'email' => 'contact@societe.mg',
            'adresse' => 'Lot II M 12 bis',
            'activite' => 'Commerce de gros',
        ]);

        Alerte::create([
            'contribuable_id' => $c->id,
            'regle' => 'r1',
            'type_impot' => 'TVA',
            'annee_fiscale' => 2026,
            'mois' => 0,
            'periodes_manquees' => 0,
            'periodes_declarees' => 0,
            'niveau' => 'eleve',
            'motif' => 'Écart entre le CA IR et le CA TVA.',
            'statut' => Alerte::A_TRAITER,
        ]);

        BaseRisque::create([
            'contribuable_id' => $c->id, 'source' => 'IR', 'annee_fiscale' => 2026, 'mois' => 0,
            'ca' => 100000000, 'resultat' => -5000000, 'ir_a_payer' => 0,
        ]);
        BaseRisque::create([
            'contribuable_id' => $c->id, 'source' => 'TVA', 'annee_fiscale' => 2026, 'mois' => 1,
            'ca' => 20000000, 'credit_tva' => 0,
        ]);
        BaseRisque::create([
            'contribuable_id' => $c->id, 'source' => 'TVA', 'annee_fiscale' => 2026, 'mois' => 2,
            'ca' => 10000000, 'credit_tva' => 500000,
        ]);

        $res = $this->getJson("/api/contribuables/{$c->id}");

        $res->assertOk()
            ->assertJsonPath('contribuable.telephone', '034 12 345 67')
            ->assertJsonPath('contribuable.email', 'contact@societe.mg')
            ->assertJsonPath('contribuable.activite', 'Commerce de gros')
            ->assertJsonPath('resultat.alertes_total', 1)
            ->assertJsonPath('resultat.par_regle.r1', 1)
            ->assertJsonPath('resultat.niveau_global', 'eleve')
            ->assertJsonPath('resultat.par_statut.a_traiter', 1)
            ->assertJsonPath('chiffres.ir.ca', 100000000)
            ->assertJsonPath('chiffres.tva.ca', 30000000)
            ->assertJsonPath('chiffres.tva.credit_tva', 500000)
            ->assertJsonPath('chiffres.ecart_ca_pct', 70)
            ->assertJsonPath('observations', []);
    }

    public function test_un_agent_ne_voit_pas_un_contribuable_dun_autre_centre(): void
    {
        Sanctum::actingAs($this->utilisateur(Role::Agent, $this->centre));
        $c = $this->contribuable($this->autreCentre, '2222222222');

        $this->getJson("/api/contribuables/{$c->id}")->assertForbidden();
        $this->get("/api/contribuables/{$c->id}/fiche.pdf")->assertForbidden();
        $this->patchJson("/api/contribuables/{$c->id}/contact", ['telephone' => '034 00 000 00'])->assertForbidden();
        $this->postJson("/api/contribuables/{$c->id}/observations", ['texte' => 'Test'])->assertForbidden();
    }

    public function test_les_coordonnees_et_les_observations_sont_enregistrees(): void
    {
        $user = $this->utilisateur(Role::Admin);
        Sanctum::actingAs($user);

        $c = $this->contribuable($this->centre, '3333333333');

        $this->patchJson("/api/contribuables/{$c->id}/contact", [
            'telephone' => '033 00 000 00',
            'email' => 'fiche@societe.mg',
            'adresse' => 'Antananarivo',
            'activite' => 'Services',
        ])->assertOk()->assertJsonPath('contribuable.telephone', '033 00 000 00');

        $this->assertDatabaseHas('contribuables', ['id' => $c->id, 'email' => 'fiche@societe.mg']);

        $this->postJson("/api/contribuables/{$c->id}/observations", ['texte' => 'Relance téléphonique effectuée.'])
            ->assertCreated()
            ->assertJsonPath('observations.0.texte', 'Relance téléphonique effectuée.')
            ->assertJsonPath('observations.0.auteur', $user->nom);

        $this->assertDatabaseHas('fiche_observations', ['contribuable_id' => $c->id, 'user_id' => $user->id]);

        $this->postJson("/api/contribuables/{$c->id}/observations", ['texte' => ''])->assertUnprocessable();
        $this->patchJson("/api/contribuables/{$c->id}/contact", ['email' => 'pas-un-email'])->assertUnprocessable();
    }

    public function test_le_pdf_de_la_fiche_est_genere(): void
    {
        Sanctum::actingAs($this->utilisateur(Role::Central));
        $c = $this->contribuable($this->centre, '4444444444');
        $c->observations()->create(['user_id' => null, 'texte' => 'Observation reprise dans le PDF.']);

        $res = $this->get("/api/contribuables/{$c->id}/fiche.pdf");

        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('fiche-4444444444.pdf', (string) $res->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
