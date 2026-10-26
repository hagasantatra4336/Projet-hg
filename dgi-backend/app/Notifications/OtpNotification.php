<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification Laravel envoyant le code OTP par e-mail.
 *
 * - Destinataire : l'adresse passée à Notification::route('mail', $email)
 *   (envoi "à la demande" : l'utilisateur n'existe pas encore en base).
 * - Expéditeur   : MAIL_FROM_ADDRESS / MAIL_FROM_NAME du fichier .env.
 *
 * Contextes : « inscription » (code initial), « approbation » (le chef de centre vient d'approuver)
 * et « connexion ».
 *
 * Volontairement SANS "implements ShouldQueue" : l'e-mail part immédiatement,
 * sans avoir besoin de lancer "php artisan queue:work".
 */
class OtpNotification extends Notification
{
    public function __construct(
        public string $nom,
        public string $code,
        public int $minutes = 5,
        public string $contexte = 'inscription', // 'inscription', 'approbation' ou 'connexion'
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sujet = match ($this->contexte) {
            'connexion' => 'Votre code de connexion DGI',
            'approbation' => 'Demande de compte approuvée — votre code DGI',
            default => 'Votre code de vérification DGI',
        };

        return (new MailMessage)
            ->subject($sujet)
            ->view('emails.otp', [
                'nom' => $this->nom,
                'code' => $this->code,
                'minutes' => $this->minutes,
                'contexte' => $this->contexte,
            ]);
    }
}
