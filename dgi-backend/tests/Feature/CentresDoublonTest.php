<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un centre ne peut pas être ajouté deux fois, même si l'écriture diffère :
 * « DGE » et « Direction des Grandes Entreprises (DGE) » désignent la même entité.
 */
class CentresDoublonTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $fonction = Fonction::create(['nom' => 'Super administrateur', 'role' => Role::Superadmin]);
        $centre = Centre::create(['nom' => 'Centre de rattachement']);

        $user = User::create([
            'nom' => 'Super Admin',
            'email' => 'superadmin-'.uniqid().'@doublon.test',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_un_sigle_ne_peut_pas_doublonner_un_nom_complet(): void
    {
        $this->superadmin();
        Centre::create(['nom' => 'Direction des Grandes Entreprises (DGE)']);

        foreach ([
            'DGE',
            'dge',
            'Direction des Grandes Entreprises',
            'DIRECTION GÉNÉRALE DES GRANDES ENTREPRISES (DGE)',
            '  direction   des   grandes   entreprises  ',
        ] as $nom) {
            $this->postJson('/api/centres', ['nom' => $nom])
                ->assertStatus(422)
                ->assertJsonValidationErrors('nom');
        }
    }

    public function test_l_ordre_inverse_sigle_puis_nom_complet_est_aussi_refuse(): void
    {
        $this->superadmin();
        Centre::create(['nom' => 'DGE']);

        $this->postJson('/api/centres', ['nom' => 'Direction des Grandes Entreprises (DGE)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom');
    }

    public function test_les_variantes_d_ecriture_sont_refusees(): void
    {
        $this->superadmin();
        Centre::create(['nom' => 'Direction Générale des Impôts (DGI)']);

        $this->postJson('/api/centres', ['nom' => 'DIRECTION GENERALE DES IMPOTS'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom');

        $this->postJson('/api/centres', ['nom' => 'direction   générale   des   impôts (dgi)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom');
    }

    public function test_des_centres_reellement_differents_sont_acceptes(): void
    {
        $this->superadmin();
        Centre::create(['nom' => 'CENTRE FISCAL TANA I']);
        Centre::create(['nom' => 'DRI ANALAMANGA']);

        $this->postJson('/api/centres', ['nom' => 'CENTRE FISCAL TANA II'])->assertCreated();
        $this->postJson('/api/centres', ['nom' => 'DRI ALAOTRA MANGORO'])->assertCreated();
        $this->postJson('/api/centres', ['nom' => 'DRI ANOSY'])->assertCreated();
    }

    public function test_modifier_un_centre_sans_changer_son_nom_reste_possible_meme_s_il_existe_un_doublon(): void
    {
        $this->superadmin();
        $doublon = Centre::create(['nom' => 'Direction des Grandes Entreprises (DGE)']);
        Centre::create(['nom' => 'DGE']); // doublon déjà présent en base

        $this->putJson('/api/centres/'.$doublon->id, [
            'nom' => $doublon->nom,
            'adresse' => 'Nouvelle adresse',
        ])->assertOk()->assertJsonPath('adresse', 'Nouvelle adresse');
    }

    public function test_renommer_un_centre_vers_un_nom_deja_utilise_est_refuse(): void
    {
        $this->superadmin();
        Centre::create(['nom' => 'DGE']);
        $autre = Centre::create(['nom' => 'Direction des Grandes Entreprises (DGE)']);

        $this->putJson('/api/centres/'.$autre->id, ['nom' => 'dge'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom');
    }

    public function test_le_message_indique_le_centre_existant(): void
    {
        $this->superadmin();
        Centre::create(['nom' => 'Direction des Grandes Entreprises (DGE)']);

        $reponse = $this->postJson('/api/centres', ['nom' => 'DGE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom');

        $this->assertStringContainsString(
            'Direction des Grandes Entreprises (DGE)',
            (string) $reponse->json('errors.nom.0'),
        );
    }
}
