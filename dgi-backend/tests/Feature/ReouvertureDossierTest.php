<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\DossierHistorique;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Réouverture d'un dossier clôturé : réservée au chef de centre (admin), au central et au superadmin.
 * Chaque action de dossier (prise, libération, clôture, réouverture) laisse une ligne de journal.
 */
class ReouvertureDossierTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Contribuable $contribuable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre réouverture']);
        $this->contribuable = Contribuable::create([
            'nif' => '2222222222',
            'nom' => 'Société réouverture',
            'centre_id' => $this->centre->id,
            'etat' => 1,
        ]);

        Alerte::create([
            'contribuable_id' => $this->contribuable->id,
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
    }

    private function utilisateur(Role $role, string $nom): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction ' . $role->value . ' ' . $nom, 'role' => $role]);

        return User::create([
            'nom' => $nom,
            'email' => $role->value . '.' . strtolower(str_replace(' ', '', $nom)) . '@reouverture.test',
            'password' => 'motdepasse123',
            'centre_id' => $this->centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    private function alerte(): Alerte
    {
        return Alerte::firstOrFail();
    }

    private function fermer(User $par): void
    {
        Sanctum::actingAs($par);

        $this->patchJson('/api/defaillances/' . $this->alerte()->id, ['groupe' => true, 'statut' => Alerte::TRAITEE])
            ->assertOk();

        $this->assertSame(Alerte::TRAITEE, $this->alerte()->fresh()->statut);
    }

    public function test_un_agent_ne_peut_pas_rouvrir_un_dossier_traite(): void
    {
        $agent = $this->utilisateur(Role::Agent, 'Agent Un');
        $this->fermer($agent);

        Sanctum::actingAs($agent);
        $this->patchJson('/api/defaillances/' . $this->alerte()->id, ['groupe' => true, 'statut' => Alerte::A_TRAITER])
            ->assertForbidden();

        $this->assertSame(Alerte::TRAITEE, $this->alerte()->fresh()->statut);
        $this->assertSame(0, DossierHistorique::where('action', DossierHistorique::REOUVERTURE)->count());
    }

    public function test_le_chef_de_centre_rouvre_et_libere_le_dossier(): void
    {
        $agent = $this->utilisateur(Role::Agent, 'Agent Deux');
        $chef = $this->utilisateur(Role::Admin, 'Chef Centre');
        $this->fermer($agent);

        Sanctum::actingAs($chef);
        $this->patchJson('/api/defaillances/' . $this->alerte()->id, ['groupe' => true, 'statut' => Alerte::A_TRAITER])
            ->assertOk();

        $alerte = $this->alerte()->fresh();
        $this->assertSame(Alerte::A_TRAITER, $alerte->statut);
        $this->assertNull($alerte->getAttribute('pris_par'), 'La réouverture doit libérer le dossier.');

        $journal = DossierHistorique::where('action', DossierHistorique::REOUVERTURE)->get();
        $this->assertCount(1, $journal);
        $this->assertSame('Chef Centre', $journal->first()->auteur);
        $this->assertSame(Alerte::TRAITEE, $journal->first()->statut_avant);
        $this->assertSame(Alerte::A_TRAITER, $journal->first()->statut_apres);

        $this->getJson('/api/defaillances/' . $alerte->id . '/dossier')
            ->assertOk()
            ->assertJsonPath('historique.0.action', DossierHistorique::REOUVERTURE)
            ->assertJsonPath('historique.0.auteur', 'Chef Centre');
    }

    public function test_la_cloture_est_journalisee_avec_son_auteur(): void
    {
        $agent = $this->utilisateur(Role::Agent, 'Agent Trois');
        $this->fermer($agent);

        $cloture = DossierHistorique::where('action', DossierHistorique::CLOTURE)->get();
        $this->assertCount(1, $cloture);
        $this->assertSame('Agent Trois', $cloture->first()->auteur);
        $this->assertSame(Alerte::A_TRAITER, $cloture->first()->statut_avant);
        $this->assertSame(Alerte::TRAITEE, $cloture->first()->statut_apres);
    }

    public function test_les_prises_repetees_ne_dupliquent_pas_le_journal(): void
    {
        $agent = $this->utilisateur(Role::Agent, 'Agent Quatre');
        Sanctum::actingAs($agent);

        // La page de traitement renouvelle la prise toutes les 5 minutes : une seule ligne de journal.
        $this->postJson('/api/defaillances/' . $this->alerte()->id . '/prendre', ['groupe' => true])->assertOk();
        $this->postJson('/api/defaillances/' . $this->alerte()->id . '/prendre', ['groupe' => true])->assertOk();

        $this->assertSame(1, DossierHistorique::where('action', DossierHistorique::PRISE)->count());

        $this->postJson('/api/defaillances/' . $this->alerte()->id . '/liberer', ['groupe' => true])->assertOk();

        $this->assertSame(1, DossierHistorique::where('action', DossierHistorique::LIBERATION)->count());
        $this->assertNull($this->alerte()->fresh()->getAttribute('pris_par'));
    }
}
