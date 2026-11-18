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

        $this->postJson('/api/register', $inscription + [
            'im' => 'AB1234',
            'telephone' => '0341234567',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['im']);
    }

    public function test_inscription_verifie_que_la_fonction_correspond_au_type_de_centre(): void
    {
        $centreFiscal = Centre::create(['nom' => 'Centre fiscal', 'type_centre' => '-']);
        $centreCentral = Centre::create(['nom' => 'Service central', 'type_centre' => Centre::TYPE_CENTRAL]);
        $fonctionAgent = Fonction::create(['nom' => 'Agent test', 'role' => Role::Agent]);
        $fonctionCentral = Fonction::create(['nom' => 'Central test', 'role' => Role::Central]);

        $base = [
            'nom' => 'Nouvel utilisateur',
            'im' => '123456',
            'telephone' => '0341234567',
        ];

        $this->postJson('/api/register', $base + [
            'email' => 'central-avec-agent@example.test',
            'centre_id' => $centreCentral->id,
            'fonction_id' => $fonctionAgent->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fonction_id']);

        $this->postJson('/api/register', $base + [
            'email' => 'fiscal-avec-central@example.test',
            'centre_id' => $centreFiscal->id,
            'fonction_id' => $fonctionCentral->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fonction_id']);
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

    public function test_verification_im_indique_si_le_matricule_est_deja_utilise(): void
    {
        $fonction = Fonction::create(['nom' => 'Agent test', 'role' => Role::Agent]);
        User::create([
            'nom' => 'Agent existant',
            'email' => 'agent-im-existant@example.test',
            'im' => '123456',
            'password' => 'motdepasse123',
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/register/check-im', ['im' => '654321'])
            ->assertOk()
            ->assertJsonPath('valide', true)
            ->assertJsonPath('disponible', true);

        $this->postJson('/api/register/check-im', ['im' => '123456'])
            ->assertOk()
            ->assertJsonPath('valide', true)
            ->assertJsonPath('disponible', false);

        $this->postJson('/api/register/check-im', ['im' => '12A456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['im']);
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
