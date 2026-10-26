<?php

namespace App\Services;

use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Envoi des codes OTP par e-mail, avec une seconde tentative.
 *
 * L'envoi SMTP (TLS + authentification + remise) peut prendre plusieurs secondes et échouer
 * ponctuellement (DNS, coupure, limitation Gmail). Deux tentatives suffisent dans la très grande
 * majorité des cas ; l'erreur reste visible dans les logs et peut être remontée à l'appelant.
 */
class OtpMailer
{
    private const TENTATIVES = 2;

    /** Envoi immédiat : l'appelant attend (et sait si l'e-mail est parti). */
    public function envoyer(string $email, string $nom, string $code, int $minutes, string $contexte = 'inscription'): void
    {
        $derniere = null;

        for ($tentative = 1; $tentative <= self::TENTATIVES; $tentative++) {
            try {
                Notification::route('mail', $email)
                    ->notify(new OtpNotification($nom, $code, $minutes, $contexte));

                return;
            } catch (Throwable $e) {
                $derniere = $e;
                Log::warning(sprintf(
                    'Envoi OTP échoué (tentative %d/%d) pour %s : %s',
                    $tentative,
                    self::TENTATIVES,
                    $email,
                    $e->getMessage()
                ));

                if ($tentative < self::TENTATIVES) {
                    usleep(750_000);
                }
            }
        }

        throw $derniere;
    }

    /**
     * Envoi APRÈS la réponse au navigateur (connexion) : la page du code s'affiche tout de suite.
     * L'échec est journalisé ; l'utilisateur peut demander un renvoi.
     */
    public function envoyerApresReponse(string $email, string $nom, string $code, int $minutes, string $contexte = 'connexion'): void
    {
        app()->terminating(function () use ($email, $nom, $code, $minutes, $contexte): void {
            try {
                $this->envoyer($email, $nom, $code, $minutes, $contexte);
            } catch (Throwable $e) {
                Log::error('Envoi OTP impossible : ' . $e->getMessage());
            }
        });
    }
}
