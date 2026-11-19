<?php

namespace App\Notifications;

use App\Notifications\Concerns\IncludesDgiEmailLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de confirmation d'une modification du profil (lien à usage unique, SANS code OTP).
 * Envoyé après la réponse HTTP pour ne pas faire attendre l'utilisateur pendant l'envoi SMTP.
 */
class ProfileChangeNotification extends Notification
{
    use IncludesDgiEmailLogo;

    /** @param array<int, string> $champs libellés des champs à modifier (jamais les valeurs) */
    public function __construct(
        public string $nom,
        public array $champs,
        public string $url,
        public int $minutes = 30,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withDgiEmailLogo((new MailMessage)
            ->subject('Confirmez la modification de votre compte DGI')
            ->view('emails.profile-change', [
                'nom' => $this->nom,
                'champs' => $this->champs,
                'url' => $this->url,
                'minutes' => $this->minutes,
            ]));
    }
}
