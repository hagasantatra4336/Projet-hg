<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Centre;
use App\Models\DemandeInscription;
use App\Models\Fonction;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Notifications\RappelOtpApprobationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Code d'approbation valable 24 heures : rappel une heure avant l'expiration, puis clôture
 * de la demande. L'agent dont le code a expiré doit déposer une nouvelle demande.
 */
class RappelOtpApprobationTest extends TestCase
{
    use RefreshDatabase;

    private Centre $centre;

    private Fonction $fonctionAgent;

    private Fonction $fonctionSuperadmin;

    private static int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Centre::create(['nom' => 'Centre rappel OTP']);
        $this->fonctionAgent = Fonction::create(['nom' => 'Agent rappel OTP', 'role' => Role::Agent]);
        $this->fonctionSuperadmin = Fonction::create(['nom' => 'Superadmin rappel OTP', 'role' => Role::Superadmin]);
    }

    private function superadmin(): User
    {
        return User::create([
            'nom' => 'Super administrateur',
            'email' => 'super-rappel@test.local',
            'password' => 'motdepasse123',
            'centre_id' => null,
            'fonction_id' => $this->fonctionSuperadmin->id,
            'email_verified_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function demande(array $attrs = []): DemandeInscription
    {
        self::$compteur++;

        return DemandeInscription::create($attrs + [
            'request_id' => str_pad('rappel' . self::$compteur, 48, 'x', STR_PAD_LEFT),
            'nom' => 'Agent rappel ' . self::$compteur,
            'email' => 'agent-rappel' . self::$compteur . '@test.local',
            'centre_id' => $this->centre->id,
            'fonction_id' => $this->fonctionAgent->id,
            'statut' => DemandeInscription::EN_ATTENTE,
            'niveau_approbation' => DemandeInscription::NIVEAU_CHEF,
            'expires_at' => now()->addDays(3),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function demandeApprouvee(array $attrs = []): DemandeInscription
    {
        return $this->demande($attrs + [
            'statut' => DemandeInscription::APPROUVEE,
            'otp_hash' => str_repeat('a', 64),
            'otp_expires_at' => now()->addMinutes(45),
            'otp_envoye_le' => now()->subHours(23),
            'otp_renvois' => 1,
        ]);
    }

    public function test_l_approbation_accorde_24_heures_et_arme_le_rappel(): void
    {
        Notification::fake();

        $demande = $this->demande();
        Sanctum::actingAs($this->superadmin());

        $this->postJson("/api/demandes-inscription/{$demande->id}/approuver")->assertOk();

        $demande->refresh();

        $this->assertSame(DemandeInscription::APPROUVEE, $demande->statut);
        $this->assertNotNull($demande->otp_hash);
        $this->assertNull($demande->rappel_otp_envoye_le);
        $this->assertTrue(
            $demande->otp_expires_at->between(now()->addHours(23)->addMinutes(55), now()->addHours(24)->addMinutes(5)),
            'Le code doit être valable 24 heures après l\'approbation.'
        );

        Notification::assertSentOnDemand(OtpNotification::class);
    }

    public function test_un_rappel_part_une_heure_avant_l_expiration(): void
    {
        Notification::fake();

        $demande = $this->demandeApprouvee();

        $this->artisan('dgi:rappeler-otp-approbation')->assertExitCode(0);

        Notification::assertSentOnDemand(RappelOtpApprobationNotification::class);
        $this->assertNotNull($demande->refresh()->rappel_otp_envoye_le);
    }

    public function test_le_rappel_n_est_envoye_qu_une_seule_fois(): void
    {
        Notification::fake();

        $this->demandeApprouvee();

        $this->artisan('dgi:rappeler-otp-approbation')->assertExitCode(0);
        $this->artisan('dgi:rappeler-otp-approbation')->assertExitCode(0);

        Notification::assertSentOnDemandTimes(RappelOtpApprobationNotification::class, 1);
    }

    public function test_aucun_rappel_tant_que_le_code_expire_dans_plus_d_une_heure(): void
    {
        Notification::fake();

        $this->demandeApprouvee(['otp_expires_at' => now()->addHours(3)]);

        $this->artisan('dgi:rappeler-otp-approbation')->assertExitCode(0);

        Notification::assertSentOnDemandTimes(RappelOtpApprobationNotification::class, 0);
    }

    public function test_un_nouveau_code_relance_le_rappel(): void
    {
        Notification::fake();

        $demande = $this->demandeApprouvee([
            'otp_envoye_le' => now()->subMinutes(5),
            'rappel_otp_envoye_le' => now()->subMinutes(10),
        ]);

        $this->postJson('/api/register/resend-otp', ['request_id' => $demande->request_id])->assertOk();

        $this->assertNull($demande->refresh()->rappel_otp_envoye_le);
        Notification::assertSentOnDemand(OtpNotification::class);
    }

    public function test_un_code_non_saisi_dans_les_24_heures_cloture_la_demande(): void
    {
        $demande = $this->demandeApprouvee(['otp_expires_at' => now()->subMinute()]);

        $this->artisan('dgi:expirer-otp-approbation')->assertExitCode(0);

        $demande->refresh();

        $this->assertSame(DemandeInscription::EXPIREE, $demande->statut);
        $this->assertNull($demande->otp_hash);
        $this->assertSame(DemandeInscription::EXPIREE, $demande->statutEffectif());
    }

    public function test_une_demande_approuvee_expiree_ne_bloque_plus_une_nouvelle_inscription(): void
    {
        $this->demandeApprouvee([
            'im' => '123456',
            'otp_expires_at' => now()->subMinute(),
        ]);

        // Le matricule est encore retenu tant que la demande approuvée n'est pas clôturée.
        $this->postJson('/api/register/check-im', ['im' => '123456'])
            ->assertOk()
            ->assertJson(['disponible' => false]);

        $this->artisan('dgi:expirer-otp-approbation')->assertExitCode(0);

        $this->postJson('/api/register/check-im', ['im' => '123456'])
            ->assertOk()
            ->assertJson(['disponible' => true]);
    }
}
