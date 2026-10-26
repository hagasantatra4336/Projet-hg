<?php

namespace App\Notifications;

use App\Models\DemandeInscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient les approbateurs (chef de centre, ou à défaut central / superadmin) qu'un agent
 * demande un compte. Le lien ouvre la page « Demandes d'inscription » de l'application.
 */
class DemandeInscriptionNotification extends Notification
{
    public function __construct(
        public DemandeInscription $demande,
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
            ->subject('Nouvelle demande de compte — ' . $this->demande->nom)
            ->view('emails.demande-inscription', [
                'nom' => $this->demande->nom,
                'email' => $this->demande->email,
                'im' => $this->demande->im,
                'telephone' => $this->demande->telephone,
                'centre' => $this->demande->centre?->nom,
                'fonction' => $this->demande->fonction?->nom,
                'deposee_le' => $this->demande->created_at?->format('d/m/Y H:i'),
                'url' => $this->url,
            ]);
    }
}
