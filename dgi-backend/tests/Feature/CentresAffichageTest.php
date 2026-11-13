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
        // Masqué, mais pas effacé : la page de gestion (liste complète ?tous=1) le retourne
        // toujours, alors que la liste par défaut — celle des champs de recherche — l'exclut.
        $ids = collect($this->getJson('/api/centres?tous=1')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($centre->id));

        $idsParDefaut = collect($this->getJson('/api/centres')->assertOk()->json())->pluck('id');
        $this->assertFalse($idsParDefaut->contains($centre->id));
    }

    public function test_le_centre_masque_disparait_de_tous_les_champs_de_recherche_de_centre(): void
    {
        $fiscal = Centre::create(['nom' => 'CF présent partout', 'type_centre' => '-']);
        $centralVisible = Centre::create(['nom' => 'Central visible partout', 'type_centre' => 'central', 'affiche' => true]);
        $centralMasque = Centre::create(['nom' => 'Central masqué', 'type_centre' => 'central', 'affiche' => false]);

        $this->utilisateur(Role::Superadmin);

        // GET /centres : filtres des archives et des défaillances, compteurs.
        $parDefaut = collect($this->getJson('/api/centres')->assertOk()->json())->pluck('id');
        $this->assertTrue($parDefaut->contains($fiscal->id));
        $this->assertTrue($parDefaut->contains($centralVisible->id));
        $this->assertFalse($parDefaut->contains($centralMasque->id));

        // Liste complète réservée à la gestion des centres.
        $tous = collect($this->getJson('/api/centres?tous=1')->assertOk()->json())->pluck('id');
        $this->assertTrue($tous->contains($centralMasque->id));

        // GET /users/options : formulaire et filtre « par centre » de la page Utilisateurs.
        $options = collect($this->getJson('/api/users/options')->assertOk()->json('centres'))->pluck('id');
        $this->assertTrue($options->contains($fiscal->id));
        $this->assertFalse($options->contains($centralMasque->id));
    }

    public function test_un_centre_masque_ne_peut_pas_etre_choisi_pour_un_changement_de_centre(): void
    {
        $masque = Centre::create(['nom' => 'Central masqué profil', 'type_centre' => 'central', 'affiche' => false]);
        $visible = Centre::create(['nom' => 'Central visible profil', 'type_centre' => 'central', 'affiche' => true]);

        $user = $this->utilisateur(Role::Central);

        // Contournement par l'API : le centre masqué est refusé comme à l'inscription.
        $this->postJson('/api/profile/request-change', [
            'nom' => $user->nom,
            'current_password' => 'motdepasse123',
            'centre_id' => $masque->id,
        ])->assertStatus(422)->assertJsonValidationErrors('centre_id');

        $this->postJson('/api/profile/request-change', [
            'nom' => $user->nom,
            'current_password' => 'motdepasse123',
            'centre_id' => $visible->id,
        ])->assertOk();
    }

    public function test_un_centre_fiscal_ne_peut_pas_etre_masque(): void
    {
        $centre = Centre::create(['nom' => 'CF non masquable', 'type_centre' => '-', 'affiche' => true]);

        $this->utilisateur(Role::Superadmin);
        $this->patchJson('/api/centres/'.$centre->id.'/affichage', ['affiche' => false])
            ->assertStatus(422);

        $this->assertTrue($centre->refresh()->affiche);
    }

    public function test_le_superadmin_cree_un_centre_avec_le_type_et_l_affichage_choisis(): void
    {
        $this->utilisateur(Role::Superadmin);

        $this->postJson('/api/centres', [
            'nom' => 'Service central masqué',
            'type_centre' => 'central',
            'affiche' => false,
        ])->assertCreated()->assertJsonPath('type_centre', 'central')->assertJsonPath('affiche', false);

        $this->postJson('/api/centres', [
            'nom' => 'Service central coché',
            'type_centre' => 'central',
        ])->assertCreated()->assertJsonPath('type_centre', 'central')->assertJsonPath('affiche', true);

        $this->postJson('/api/centres', ['nom' => 'Centre fiscal simple'])
            ->assertCreated()->assertJsonPath('type_centre', '-')->assertJsonPath('affiche', true);

        // Un centre fiscal reste proposé même si l'affichage est demandé à faux.
        $this->postJson('/api/centres', ['nom' => 'Centre fiscal forcé', 'affiche' => false])
            ->assertCreated()->assertJsonPath('type_centre', '-')->assertJsonPath('affiche', true);
    }

    public function test_un_utilisateur_central_ne_peut_pas_creer_ni_modifier_le_type_ou_l_affichage(): void
    {
        $this->utilisateur(Role::Central);

        $cree = $this->postJson('/api/centres', [
            'nom' => 'Tentative central',
            'type_centre' => 'central',
            'affiche' => false,
        ])->assertCreated()->json();

        $this->assertSame('-', $cree['type_centre']);
        $this->assertTrue($cree['affiche']);

        $centre = Centre::create(['nom' => 'SSIF protégé', 'type_centre' => 'central', 'affiche' => true]);

        $this->putJson('/api/centres/'.$centre->id, [
            'nom' => 'SSIF renommé',
            'type_centre' => '-',
            'affiche' => false,
        ])->assertOk()->assertJsonPath('type_centre', 'central')->assertJsonPath('affiche', true);

        $this->assertSame('SSIF renommé', $centre->refresh()->nom);
    }

    public function test_repasser_un_service_central_en_centre_fiscal_le_reaffiche(): void
    {
        $centre = Centre::create(['nom' => 'SSIF redevenu fiscal', 'type_centre' => 'central', 'affiche' => false]);

        $this->utilisateur(Role::Superadmin);
        $this->putJson('/api/centres/'.$centre->id, ['nom' => $centre->nom, 'type_centre' => '-'])
            ->assertOk()->assertJsonPath('type_centre', '-')->assertJsonPath('affiche', true);

        $noms = collect($this->getJson('/api/signup-options')->assertOk()->json('centres'))->pluck('nom');
        $this->assertContains($centre->nom, $noms);
    }
}
