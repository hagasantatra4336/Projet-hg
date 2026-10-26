<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Adresse du front React utilisée dans les liens envoyés par e-mail.
 * On privilégie l'origine depuis laquelle la demande a été faite (en-tête Origin), si elle est
 * déclarée dans config/cors.php : le lien fonctionne alors aussi depuis un smartphone.
 */
class FrontendUrl
{
    public static function base(?Request $request = null): string
    {
        $origin = rtrim((string) $request?->headers->get('Origin', ''), '/');

        if ($origin !== '' && self::origineDeConfiance($origin)) {
            return $origin;
        }

        return rtrim((string) config('dgi.frontend_url', 'http://localhost:5173'), '/');
    }

    private static function origineDeConfiance(string $origin): bool
    {
        if (in_array($origin, (array) config('cors.allowed_origins', []), true)) {
            return true;
        }

        foreach ((array) config('cors.allowed_origins_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }
}
