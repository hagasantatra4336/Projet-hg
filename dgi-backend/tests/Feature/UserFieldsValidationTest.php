<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserFieldsValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inscription_refuse_un_im_et_un_telephone_de_mauvaise_longueur(): void
    {
        $centre = Centre::create(['nom' => 'Centre test']);
        $fonction = Fonction::create(['nom' => 'Agent test', 'role' => Role::Agent]);

        $inscription = [
            'nom' => 'Nouvel agent',
            'email' => 'agent-validation@example.test',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
        ];

        $this->postJson('/api/register', $inscription)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['im', 'telephone']);

        $this->postJson('/api/register', $inscription + [
            'im' => '12345',
            'telephone' => '034123456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['im', 'telephone']);
    }

    public function test_un_superadmin_peut_creer_un_compte_avec_un_im_de_six_caracteres_et_dix_chiffres(): void
    {
        $centre = Centre::create(['nom' => 'Centre test']);
        $agentFonction = Fonction::create(['nom' => 'Agent test', 'role' => Role::Agent]);
        $superadminFonction = Fonction::create(['nom' => 'Superadmin test', 'role' => Role::Superadmin]);
        $superadmin = User::create([
            'nom' => 'Superadmin',
            'email' => 'superadmin-validation@example.test',
            'password' => 'motdepasse123',
            'fonction_id' => $superadminFonction->id,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($superadmin);

        $this->postJson('/api/users', [
            'nom' => 'Agent valide',
            'email' => 'agent-valide@example.test',
            'im' => 'AB1234',
            'telephone' => '0341234567',
            'centre_id' => $centre->id,
            'fonction_id' => $agentFonction->id,
            'password' => 'motdepasse123',
        ])
            ->assertCreated()
            ->assertJsonPath('im', 'AB1234')
            ->assertJsonPath('telephone', '0341234567');
    }

    public function test_modification_du_profil_refuse_un_telephone_de_mauvaise_longueur(): void
    {
        $fonction = Fonction::create(['nom' => 'Agent test', 'role' => Role::Agent]);
        $user = User::create([
            'nom' => 'Agent',
            'email' => 'agent-profil@example.test',
            'im' => 'AB1234',
            'telephone' => '0341234567',
            'password' => 'motdepasse123',
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/request-change', [
            'nom' => 'Agent',
            'telephone' => '034123456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telephone']);
    }
}
