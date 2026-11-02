<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Liste de travail nominative : un agent ne voit et n'ouvre que les dossiers qui lui sont assignés
 * (et les archives qu'il a clôturées). Les autres rôles gardent leur périmètre (centre ou tous les centres).
 */
class VisibiliteAgentTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Centre $autreCentre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre visibilité']);
        $this->autreCentre = Centre::create(['nom' => 'Centre voisin']);
    }

    private function alerte(string $nif, string $nom, int $centreId, array $attributs = []): Alerte
    {
        $contribuable = Contribuable::firstOrCreate(
            ['nif' => $nif],
            ['nom' => $nom, 'centre_id' => $centreId, 'etat' => 1]
        );

        return Alerte::create($attributs + [
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

    private function utilisateur(Role $role, ?Centre $centre, string $nom, string $email): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.$email, 'role' => $role]);

        return User::create([
            'nom' => $nom,
            'email' => $email,
            'password' => 'motdepasse123',
            'centre_id' => $centre?->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    public function test_un_agent_ne_voit_que_les_dossiers_qui_lui_sont_assignes(): void
    {
        $agent = $this->utilisateur(Role::Agent, $this->centre, 'Agent Titulaire', 'titulaire@visibilite.test');
        $collegue = $this->utilisateur(Role::Agent, $this->centre, 'Agent Collègue', 'collegue@visibilite.test');

        $this->alerte('1111111111', 'Dossier assigné', $this->centre->id, [
            'pris_par' => $agent->id,
            'pris_le' => now(),
        ]);
        $this->alerte('2222222222', 'Dossier libre', $this->centre->id);
        $this->alerte('3333333333', 'Dossier du collègue', $this->centre->id, [
            'pris_par' => $collegue->id,
            'pris_le' => now(),
        ]);
        $this->alerte('4444444444', 'Dossier lointain', $this->autreCentre->id, [
            'pris_par' => $agent->id,
            'pris_le' => now(),
        ]);

        Sanctum::actingAs($agent);

        $noms = collect($this->getJson('/api/defaillances?grouper=1')->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier assigné'], $noms);

        // Le filtre « En traitement » de l'écran garde le même périmètre.
        $enCours = collect($this->getJson('/api/defaillances?grouper=1&statut=en_traitement')->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier assigné'], $enCours);
    }

    public function test_un_agent_ne_peut_pas_ouvrir_un_dossier_d_un_autre_agent(): void
    {
        $agent = $this->utilisateur(Role::Agent, $this->centre, 'Agent Ouvert', 'ouvert@visibilite.test');
        $collegue = $this->utilisateur(Role::Agent, $this->centre, 'Agent Fermé', 'ferme@visibilite.test');

        $sien = $this->alerte('1111111111', 'Mon dossier', $this->centre->id, [
            'pris_par' => $agent->id,
            'pris_le' => now(),
        ]);
        $autre = $this->alerte('2222222222', 'Dossier fermé', $this->centre->id, [
            'pris_par' => $collegue->id,
            'pris_le' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/defaillances/'.$sien->id.'/dossier')->assertOk();

        $this->getJson('/api/defaillances/'.$autre->id.'/dossier')->assertForbidden();
        $this->patchJson('/api/defaillances/'.$autre->id, ['groupe' => true, 'statut' => Alerte::TRAITEE])->assertForbidden();
        $this->postJson('/api/defaillances/'.$autre->id.'/prendre', ['groupe' => true])->assertForbidden();
        $this->postJson('/api/defaillances/'.$autre->id.'/liberer', ['groupe' => true])->assertForbidden();
    }

    public function test_un_agent_consulte_l_archive_qu_il_a_cloturee(): void
    {
        $agent = $this->utilisateur(Role::Agent, $this->centre, 'Agent Archive', 'archive@visibilite.test');

        $clos = $this->alerte('1111111111', 'Dossier clôturé', $this->centre->id, [
            'statut' => Alerte::TRAITEE,
            'traite_par' => $agent->id,
            'traite_le' => now(),
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/defaillances/'.$clos->id.'/dossier')->assertOk();
        $noms = collect($this->getJson('/api/defaillances?grouper=1&archive=1')->json('data'))->pluck('contribuable.nom')->all();
        $this->assertSame(['Dossier clôturé'], $noms);
    }

    public function test_la_liste_des_agents_pour_les_filtres_suit_le_role(): void
    {
        $chef = $this->utilisateur(Role::Admin, $this->centre, 'Chef Centre', 'chef@visibilite.test');
        $this->utilisateur(Role::Agent, $this->centre, 'Agent Ici', 'ici@visibilite.test');
        $this->utilisateur(Role::Agent, $this->autreCentre, 'Agent Ailleurs', 'ailleurs@visibilite.test');
        $central = $this->utilisateur(Role::Central, null, 'Central', 'central@visibilite.test');
        $agent = $this->utilisateur(Role::Agent, $this->centre, 'Agent Simple', 'simple@visibilite.test');

        Sanctum::actingAs($chef);
        $centreChef = $this->getJson('/api/defaillances/agents')->assertOk()->json();
        $this->assertSame(['Agent Ici', 'Agent Simple'], array_column($centreChef, 'nom'));
        $this->assertSame('Centre visibilité', $centreChef[0]['centre']);

        Sanctum::actingAs($central);
        $tous = $this->getJson('/api/defaillances/agents')->assertOk()->json();
        $this->assertSame(['Agent Ailleurs', 'Agent Ici', 'Agent Simple'], array_column($tous, 'nom'));

        $filtre = $this->getJson('/api/defaillances/agents?centre_id='.$this->autreCentre->id)->assertOk()->json();
        $this->assertSame(['Agent Ailleurs'], array_column($filtre, 'nom'));

        Sanctum::actingAs($agent);
        $this->getJson('/api/defaillances/agents')->assertForbidden();
    }
}
