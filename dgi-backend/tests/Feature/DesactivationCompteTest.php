<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesactivationCompteTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(Role $role): User
    {
        $centre = Centre::create(['nom' => 'Centre '.$role->value]);
        $fonction = Fonction::create([
            'nom' => 'Fonction '.$role->value.' '.uniqid(),
            'role' => $role,
        ]);

        return User::create([
            'nom' => 'Utilisateur '.$role->value,
            'email' => $role->value.'.'.uniqid().'@desactivation.test',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
    }

    public function test_le_superadmin_desactive_un_compte_et_la_connexion_affiche_le_motif(): void
    {
        $superadmin = $this->utilisateur(Role::Superadmin);
        $agent = $this->utilisateur(Role::Agent);
        $agent->createToken('session');

        Sanctum::actingAs($superadmin);

        $this->postJson("/api/users/{$agent->id}/desactiver", [
            'motif' => 'Absence prolongée non justifiée',
        ])
            ->assertOk()
            ->assertJsonPath('desactive', true)
            ->assertJsonPath('motif_desactivation', 'Absence prolongée non justifiée');

        $agent->refresh();
        $this->assertNotNull($agent->desactive_le);
        $this->assertSame($superadmin->id, $agent->desactive_par_id);
        $this->assertSame(0, $agent->tokens()->count());

        $this->postJson('/api/login', [
            'email' => $agent->email,
            'password' => 'motdepasse123',
        ])
            ->assertForbidden()
            ->assertJsonPath('compte_desactive', true)
            ->assertJsonPath('motif', 'Absence prolongée non justifiée')
            ->assertJsonPath('message', 'Ce compte est désactivé. Motif : Absence prolongée non justifiée');
    }

    public function test_un_mauvais_mot_de_passe_ne_revele_pas_le_motif(): void
    {
        $agent = $this->utilisateur(Role::Agent);
        $agent->update([
            'desactive_le' => now(),
            'motif_desactivation' => 'Motif confidentiel',
        ]);

        $this->postJson('/api/login', [
            'email' => $agent->email,
            'password' => 'mauvais-mot-de-passe',
        ])
            ->assertUnauthorized()
            ->assertJsonMissing(['compte_desactive' => true]);
    }

    public function test_un_central_ne_peut_pas_desactiver_un_compte(): void
    {
        $central = $this->utilisateur(Role::Central);
        $agent = $this->utilisateur(Role::Agent);

        Sanctum::actingAs($central);

        $this->postJson("/api/users/{$agent->id}/desactiver", [
            'motif' => 'Tentative non autorisée',
        ])->assertForbidden();

        $this->assertNull($agent->fresh()->desactive_le);
    }

    public function test_un_superadmin_ne_peut_pas_desactiver_son_propre_compte(): void
    {
        $superadmin = $this->utilisateur(Role::Superadmin);

        Sanctum::actingAs($superadmin);

        $this->postJson("/api/users/{$superadmin->id}/desactiver", [
            'motif' => 'Moi-même',
        ])->assertStatus(422);

        $this->assertNull($superadmin->fresh()->desactive_le);
    }

    public function test_la_reactivation_autorise_de_nouveau_la_connexion(): void
    {
        $superadmin = $this->utilisateur(Role::Superadmin);
        $agent = $this->utilisateur(Role::Agent);
        $agent->update([
            'desactive_le' => now(),
            'motif_desactivation' => 'Dossier en cours',
            'desactive_par_id' => $superadmin->id,
        ]);

        Sanctum::actingAs($superadmin);

        $this->postJson("/api/users/{$agent->id}/reactiver")
            ->assertOk()
            ->assertJsonPath('desactive', false)
            ->assertJsonPath('motif_desactivation', null);

        $this->postJson('/api/login', [
            'email' => $agent->email,
            'password' => 'motdepasse123',
        ])
            ->assertOk()
            ->assertJsonPath('otp_required', true);
    }

    public function test_une_session_encore_ouverte_est_coupee_avec_le_motif(): void
    {
        $agent = $this->utilisateur(Role::Agent);
        $token = $agent->createToken('session')->plainTextToken;
        $agent->update([
            'desactive_le' => now(),
            'motif_desactivation' => 'Compte suspendu',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('compte_desactive', true)
            ->assertJsonPath('motif', 'Compte suspendu');

        $this->assertSame(0, $agent->tokens()->count());
    }
}
