<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de confirmation d'une modification du profil (lien à usage unique, SANS code OTP).
 * Volontairement sans "implements ShouldQueue" : l'e-mail part immédiatement.
 */
class ProfileChangeNotification extends Notification
{
    /** @param array<int, string> $champs libellés des champs à modifier (jamais les valeurs) */
    public function __construct(
        public string $nom,
        public array $champs,
        public string $url,
        public int $minutes = 30,
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
            ->subject('Confirmez la modification de votre compte DGI')
            ->greeting('Bonjour ' . $this->nom . ',')
            ->line('Une modification de votre compte a été demandée : ' . implode(', ', $this->champs) . '.')
            ->action('Confirmer les modifications', $this->url)
            ->line("Ce lien est valable {$this->minutes} minutes et ne peut être utilisé qu'une seule fois.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : rien ne sera modifié. Si vous pensez que votre compte est compromis, changez votre mot de passe.")
            ->salutation('DGI');
    }
}
