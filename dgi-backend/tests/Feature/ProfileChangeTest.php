<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\User;
use App\Notifications\ProfileChangeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ProfileChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_demande_de_modification_repond_et_envoie_le_lien_de_confirmation(): void
    {
        Notification::fake();

        $centre = Centre::create(['nom' => 'Centre profil']);
        $fonction = Fonction::create(['nom' => 'Agent profil', 'role' => Role::Agent]);
        $user = User::create([
            'nom' => 'Agent profil',
            'email' => 'agent-profil-change@example.test',
            'telephone' => '0341234567',
            'password' => 'motdepasse123',
            'centre_id' => $centre->id,
            'fonction_id' => $fonction->id,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/profile/request-change', [
            'nom' => 'Agent profil modifié',
            'telephone' => '0341234567',
            'adresse' => null,
            'current_password' => 'motdepasse123',
        ])
            ->assertOk()
            ->assertJsonPath('champs.0', 'nom')
            ->assertJsonPath('message', "La demande est enregistrée. L'e-mail de confirmation est en cours d'envoi.");

        Notification::assertSentTo($user, ProfileChangeNotification::class);

        $this->getJson('/api/profile/change-status/'.$response->json('request_id'))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }

    public function test_le_courriel_de_modification_affiche_le_logo_dgi_sans_afficher_lurl(): void
    {
        $url = 'http://192.168.1.25:5173/profile/change/jeton-secret';
        $mail = (new ProfileChangeNotification('Agent profil', ['nom'], $url))->toMail(new User);
        $html = (string) $mail->render();
        $message = new Email;

        foreach ($mail->callbacks as $callback) {
            $callback($message);
        }

        $this->assertStringContainsString('cid:logo-dgi@dgi-systeme-analyse', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo.png', $html);
        $this->assertStringNotContainsString('192.168.1.25', strip_tags($html));
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('logo-dgi@dgi-systeme-analyse', $message->getAttachments()[0]->getContentId());
    }
}
