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
    /** Code d'inscription : 5 minutes à partir de l'approbation du chef de centre. */
    public const TTL_INSCRIPTION_MINUTES = 5;

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
}
