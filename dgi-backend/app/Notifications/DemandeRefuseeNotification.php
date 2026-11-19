<?php

namespace App\Notifications;

use App\Notifications\Concerns\IncludesDgiEmailLogo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Informe l'agent que sa demande de compte a été refusée (aucun code OTP n'est envoyé). */
class DemandeRefuseeNotification extends Notification
{
    use IncludesDgiEmailLogo;

    public function __construct(
        public string $nom,
        public ?string $motif,
        public string $url,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withDgiEmailLogo((new MailMessage)
            ->subject('Votre demande de compte DGI a été refusée')
            ->view('emails.demande-refusee', [
                'nom' => $this->nom,
                'motif' => $this->motif,
                'url' => $this->url,
            ]));
    }
}
