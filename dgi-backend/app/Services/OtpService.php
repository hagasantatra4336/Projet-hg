<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Génération et vérification des codes OTP.
 *
 * Empreinte HMAC-SHA256 liée à l'e-mail et à APP_KEY : le code n'est jamais stocké en clair.
 * Inutile d'utiliser bcrypt : le code n'a que 10^6 valeurs, la protection réelle vient de la limite
 * de tentatives et de la courte durée de vie.
 */
class OtpService
{
    /** Code d'inscription : 24 heures à partir de l'approbation du chef de centre. */
    public const TTL_INSCRIPTION_MINUTES = 24 * 60;

    /** Rappel envoyé une heure avant l'expiration du code d'approbation. */
    public const RAPPEL_AVANT_EXPIRATION_MINUTES = 60;

    /** Code de connexion : 10 minutes (inchangé). */
    public const TTL_CONNEXION_MINUTES = 10;

    public const MAX_TENTATIVES = 5;
    public const MAX_RENVOIS = 5;
    public const DELAI_RENVOI_SECONDES = 60;

    public function generer(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function hacher(string $email, string $code): string
    {
        return hash_hmac('sha256', Str::lower($email) . '|' . $code, (string) config('app.key'));
    }

    public function verifier(string $email, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hacher($email, $code));
    }

    /**
     * Durée en français, telle qu'elle doit apparaître dans un e-mail
     * (« 24 heures », « 10 minutes »). Les gabarits n'ont pas à connaître l'unité.
     */
    public static function dureeLisible(int $minutes): string
    {
        // Les jours ne sont utilisés qu'à partir de 48 h : « 24 heures » est plus explicite
        // qu'« 1 jour » pour un code d'activation.
        if ($minutes >= 2 * 24 * 60 && $minutes % (24 * 60) === 0) {
            $jours = intdiv($minutes, 24 * 60);

            return $jours . ' jour' . ($jours > 1 ? 's' : '');
        }

        if ($minutes >= 60 && $minutes % 60 === 0) {
            $heures = intdiv($minutes, 60);

            return $heures . ' heure' . ($heures > 1 ? 's' : '');
        }

        return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
    }
}
