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
 * Affectation nominative d'un dossier libre à un agent : chefs de centre (leurs agents),
 * central et superadmin. L'assignation vaut prise en charge (le dossier passe « En traitement »).
 */
class AssignationDefaillancesTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Centre $autreCentre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre affectation']);
        $this->autreCentre = Centre::create(['nom' => 'Centre voisin']);
    }

    private function alerte(string $nif, string $nom, int $centreId): Alerte
    {
        $contribuable = Contribuable::create(['nif' => $nif, 'nom' => $nom, 'centre_id' => $centreId, 'etat' => 1]);

        return Alerte::create([
            'contribuable_id' => $contribuable->id,
            'regle' => 'r1',
            'type_impot' => 'TVA',
            'annee_fiscale' => 2025,
            'mois' => 0,
            'periodes_manquees' => 0,
            'periodes_declarees' => 0,
            'niveau' => 'eleve',
            'motif' => 'Écart entre le CA IR et le CA TVA.',
            'statut' => Alerte::A_TRAITER,
        ]);
    }

    private function utilisateur(Role $role, Centre $centre, string $nom, string $email): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        return User::create([
            'nom' => $nom,
            'email' => $email,
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    public function test_un_chef_assigne_un_dossier_libre_a_un_agent_de_son_centre(): void
    {
        $chef = $this->utilisateur(Role::Admin, $this->centre, 'Chef Centre', 'chef@affectation.test');
        $agent = $this->utilisateur(Role::Agent, $this->centre, 'Agent Jean', 'jean@affectation.test');
        $alerte = $this->alerte('1111111111', 'Dossier à distribuer', $this->centre->id);

        Sanctum::actingAs($chef);

        // La liste des agents du dossier propose ceux du centre, pas ceux des autres centres.
        $this->utilisateur(Role::Agent, $this->autreCentre, 'Agent Lointain', 'loin@affectation.test');
        $agents = $this->getJson('/api/defaillances/'.$alerte->id.'/agents')->assertOk()->json();
        $this->assertSame(['Agent Jean'], array_column($agents, 'nom'));

        $res = $this->postJson('/api/defaillances/'.$alerte->id.'/assigner', ['user_id' => $agent->id]);
        $res->assertOk();

        // Le dossier est verrouillé au nom de l'agent, sans qu'il ait cliqué.
        $this->assertSame($agent->id, (int) $alerte->fresh()->pris_par);
        $this->assertSame('en_traitement', $res->json('groupe.statut'));

        // Le journal garde la trace de qui a assigné et à qui.
        $journal = DossierHistorique::where('action', DossierHistorique::AFFECTATION)->firstOrFail();
        $this->assertSame('Chef Centre', $journal->auteur);
        $this->assertSame('Agent Jean', $journal->detail);

        // L'agent retrouve le dossier comme sien et ne repasse pas par une prise.
        Sanctum::actingAs($agent);
        $groupe = $this->getJson('/api/defaillances?grouper=1&statut=en_traitement')->json('data.0');
        $this->assertTrue($groupe['pris_par_moi']);
    }

    public function test_un_agent_ne_peut_pas_assigner_un_dossier(): void
    {
        $agent = $this->utilisateur(Role::Agent, $this->centre, 'Agent Simple', 'simple@affectation.test');
        $autre = $this->utilisateur(Role::Agent, $this->centre, 'Agent Autre', 'autre@affectation.test');
        $alerte = $this->alerte('2222222222', 'Dossier interdit', $this->centre->id);

        Sanctum::actingAs($agent);
        $this->getJson('/api/defaillances/'.$alerte->id.'/agents')->assertForbidden();
        $this->postJson('/api/defaillances/'.$alerte->id.'/assigner', ['user_id' => $autre->id])->assertForbidden();
    }

    public function test_on_ne_peut_pas_assigner_a_un_agent_d_un_autre_centre(): void
    {
        $chef = $this->utilisateur(Role::Admin, $this->centre, 'Chef Centre', 'chef2@affectation.test');
        $lointain = $this->utilisateur(Role::Agent, $this->autreCentre, 'Agent Lointain', 'loin2@affectation.test');
        $alerte = $this->alerte('3333333333', 'Dossier du centre', $this->centre->id);

        Sanctum::actingAs($chef);
        $res = $this->postJson('/api/defaillances/'.$alerte->id.'/assigner', ['user_id' => $lointain->id]);
        $res->assertStatus(422);
        $this->assertNull($alerte->fresh()->pris_par);
    }

    public function test_un_dossier_deja_pris_ne_peut_pas_etre_assigne_sans_liberation(): void
    {
        $chef = $this->utilisateur(Role::Admin, $this->centre, 'Chef Centre', 'chef3@affectation.test');
        $preneur = $this->utilisateur(Role::Agent, $this->centre, 'Agent Preneur', 'preneur@affectation.test');
        $cible = $this->utilisateur(Role::Agent, $this->centre, 'Agent Cible', 'cible@affectation.test');
        $alerte = $this->alerte('4444444444', 'Dossier pris', $this->centre->id);

        Alerte::whereKey($alerte->id)->update(['pris_par' => $preneur->id, 'pris_le' => now()]);

        Sanctum::actingAs($chef);
        $res = $this->postJson('/api/defaillances/'.$alerte->id.'/assigner', ['user_id' => $cible->id]);
        $res->assertStatus(409);
        $this->assertStringContainsString('Agent Preneur', $res->json('message'));
        $this->assertSame($preneur->id, (int) $alerte->fresh()->pris_par);
    }

    public function test_un_chef_ne_peut_pas_assigner_un_dossier_d_un_autre_centre(): void
    {
        $chef = $this->utilisateur(Role::Admin, $this->centre, 'Chef Centre', 'chef4@affectation.test');
        $agent = $this->utilisateur(Role::Agent, $this->autreCentre, 'Agent Voisin', 'voisin@affectation.test');
        $alerte = $this->alerte('5555555555', 'Dossier voisin', $this->autreCentre->id);

        Sanctum::actingAs($chef);
        $this->postJson('/api/defaillances/'.$alerte->id.'/assigner', ['user_id' => $agent->id])->assertForbidden();
    }
}
