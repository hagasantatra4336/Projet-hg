<?php

namespace Tests\Feature;

use App\Models\DemandeInscription;
use App\Models\User;
use App\Notifications\DemandeInscriptionNotification;
use App\Notifications\DemandeRefuseeNotification;
use App\Notifications\OtpNotification;
use App\Notifications\ProfileChangeNotification;
use App\Notifications\RappelOtpApprobationNotification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    public function test_toutes_les_notifications_email_utilisent_le_logo_dgi_sans_afficher_lurl(): void
    {
        $url = 'http://192.168.1.25:5173/action/token';
        $demande = new DemandeInscription;
        $demande->forceFill([
            'nom' => 'Agent Test',
            'email' => 'agent@example.test',
            'im' => 'IM123',
            'telephone' => '0341234567',
        ]);
        $demande->created_at = now();

        $notifications = [
            new OtpNotification('Agent Test', '123456'),
            new RappelOtpApprobationNotification('Agent Test', 10, $url),
            new ProfileChangeNotification('Agent Test', ['nom'], $url),
            new DemandeRefuseeNotification('Agent Test', null, $url),
            new DemandeInscriptionNotification($demande, $url),
        ];

        foreach ($notifications as $notification) {
            $mail = $notification->toMail(new User);
            $html = (string) $mail->render();
            $message = new Email;

            foreach ($mail->callbacks as $callback) {
                $callback($message);
            }

            $this->assertStringContainsString('cid:logo-dgi@dgi-systeme-analyse', $html);
            $this->assertStringNotContainsString('192.168.1.25', strip_tags($html));
            $this->assertCount(1, $message->getAttachments());
            $this->assertSame('logo-dgi@dgi-systeme-analyse', $message->getAttachments()[0]->getContentId());
        }
    }
}
