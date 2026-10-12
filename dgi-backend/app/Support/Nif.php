<?php

namespace App\Support;

/**
 * Numéro d'Identification Fiscale : 10 chiffres, avec des zéros au début (ex. « 0000000102 »).
 * Un NIF lu comme un nombre (Excel, colonne numérique) perd ces zéros : on les rétablit.
 */
class Nif
{
    public static function normaliser(mixed $valeur): string
    {
        $v = trim((string) $valeur);

        // « 1000000001.0 » (nombre à virgule) → « 1000000001 »
        if (preg_match('/^\d+\.0+$/', $v)) {
            $v = explode('.', $v)[0];
        }

        if (preg_match('/^\d{1,10}$/', $v)) {
            return str_pad($v, 10, '0', STR_PAD_LEFT);
        }

        return $v;
    }
}
