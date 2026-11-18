<?php

namespace App\Notifications;

use App\Services\OtpService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel envoyé une heure avant l'expiration du code d'approbation : le code n'a pas encore
 * été saisi et, passé le délai, la demande d'inscription est clôturée. L'agent devra alors
 * déposer une nouvelle demande.
 */
class RappelOtpApprobationNotification extends Notification
{
    public function __construct(
        public string $nom,
        public int $minutesRestantes,
        public string $url,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Votre code d'activation expire dans " . OtpService::dureeLisible($this->minutesRestantes))
            ->view('emails.otp-rappel', [
                'nom' => $this->nom,
                'minutes' => $this->minutesRestantes,
                'url' => $this->url,
            ]);
    }
}
