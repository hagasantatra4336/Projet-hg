<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\DossierHistorique;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivitesAnomaliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_chef_de_centre_ne_voit_que_les_activites_de_son_centre(): void
    {
        $centre = Centre::create(['nom' => 'Centre local']);
        $autreCentre = Centre::create(['nom' => 'Centre voisin']);
        $this->historique($centre, '1111111111', 'prise', 'Dossier local');
        $this->historique($autreCentre, '2222222222', 'cloture', 'Dossier voisin');
        Sanctum::actingAs($this->utilisateur(Role::Admin, $centre));

        $this->getJson('/api/defaillances/activites')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.contribuable.nif', '1111111111')
            ->assertJsonPath('data.0.action_libelle', 'Prise en charge');
    }

    public function test_le_central_peut_filtrer_les_activites_par_action(): void
    {
        $centre = Centre::create(['nom' => 'Centre central']);
        $this->historique($centre, '3333333333', 'prise', 'Dossier pris');
        $this->historique($centre, '4444444444', 'affectation', 'Dossier assigné');
        Sanctum::actingAs($this->utilisateur(Role::Central, $centre));

        $this->getJson('/api/defaillances/activites?action=affectation')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.action_libelle', 'Assignation');
    }

    public function test_un_agent_ne_peut_pas_consulter_le_journal_global_des_anomalies(): void
    {
        $centre = Centre::create(['nom' => 'Centre agent']);
        Sanctum::actingAs($this->utilisateur(Role::Agent, $centre));

        $this->getJson('/api/defaillances/activites')->assertForbidden();
    }

    private function historique(Centre $centre, string $nif, string $action, string $nom): void
    {
        $contribuable = Contribuable::create([
            'nif' => $nif,
            'nom' => $nom,
            'centre_id' => $centre->id,
            'etat' => 1,
        ]);

        DossierHistorique::create([
            'contribuable_id' => $contribuable->id,
            'auteur' => 'Agent test',
            'action' => $action,
            'statut_avant' => 'a_traiter',
            'statut_apres' => $action === 'cloture' ? 'traitee' : 'en_traitement',
        ]);
    }

    private function utilisateur(Role $role, Centre $centre): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value, 'role' => $role]);

        return User::create([
            'nom' => 'Utilisateur test',
            'email' => $role->value.'@activites-anomalies.test',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }
}
