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
 * Type de centre et affichage : les centres fiscaux ('-') sont toujours proposés à l'inscription ;
 * un service central ('central') n'y figure que si le super admin l'a coché, sans jamais être supprimé.
 */
class CentresAffichageTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(Role $role): User
    {
        $fonction = Fonction::create(['nom' => 'Fonction '.$role->value.' '.uniqid(), 'role' => $role]);
        $centre = Centre::create(['nom' => 'Centre '.$role->value.' '.uniqid()]);

        $user = User::create([
            'nom' => 'Utilisateur '.$role->value,
            'email' => $role->value.'-'.uniqid().'@centres.test',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_un_service_central_masque_est_exclu_de_l_inscription_pas_un_centre_fiscal(): void
    {
        $fiscal = Centre::create(['nom' => 'CF toujours proposé', 'type_centre' => '-', 'affiche' => false]);
        $centralVisible = Centre::create(['nom' => 'SSIF visible', 'type_centre' => 'central', 'affiche' => true]);
        $centralMasque = Centre::create(['nom' => 'SSIF masqué', 'type_centre' => 'central', 'affiche' => false]);

        $noms = collect($this->getJson('/api/signup-options')->assertOk()->json('centres'))->pluck('nom')->all();

        $this->assertContains($fiscal->nom, $noms);
        $this->assertContains($centralVisible->nom, $noms);
        $this->assertNotContains($centralMasque->nom, $noms);
    }

    public function test_un_centre_central_masque_ne_peut_pas_etre_choisi_a_l_inscription(): void
    {
        $masque = Centre::create(['nom' => 'SSIF fermé', 'type_centre' => 'central', 'affiche' => false]);
        $visible = Centre::create(['nom' => 'SSIF ouvert', 'type_centre' => 'central', 'affiche' => true]);

        $base = [
            'nom' => 'Agent Test',
            'email' => 'agent-'.uniqid().'@centres.test',
            'centre_id' => $masque->id,
            'fonction_id' => 99,
        ];

        // Le contrôle du centre passe avant celui de la fonction : le centre masqué est refusé.
        $this->postJson('/api/register', $base)->assertStatus(422)->assertJsonValidationErrors('centre_id');

        $this->postJson('/api/register', ['centre_id' => $visible->id] + $base)
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('centre_id');
    }

    public function test_seul_le_superadmin_affiche_ou_masque_un_centre_central(): void
    {
        $centre = Centre::create(['nom' => 'SSIF bascule', 'type_centre' => 'central', 'affiche' => true]);

        $this->utilisateur(Role::Central);
        $this->patchJson('/api/centres/'.$centre->id.'/affichage', ['affiche' => false])->assertForbidden();
        $this->assertTrue($centre->refresh()->affiche);

        $this->utilisateur(Role::Superadmin);
        $this->patchJson('/api/centres/'.$centre->id.'/affichage', ['affiche' => false])->assertOk();

        $this->assertFalse($centre->refresh()->affiche);
        // Masqué, mais pas effacé : la gestion des centres le retourne toujours.
        $ids = collect($this->getJson('/api/centres')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($centre->id));
    }

    public function test_un_centre_fiscal_ne_peut_pas_etre_masque(): void
    {
        $centre = Centre::create(['nom' => 'CF non masquable', 'type_centre' => '-', 'affiche' => true]);

        $this->utilisateur(Role::Superadmin);
        $this->patchJson('/api/centres/'.$centre->id.'/affichage', ['affiche' => false])
            ->assertStatus(422);

        $this->assertTrue($centre->refresh()->affiche);
    }
}
