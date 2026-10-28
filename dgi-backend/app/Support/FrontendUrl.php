<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Adresse du front React utilisée dans les liens envoyés par e-mail.
 * On privilégie l'origine depuis laquelle la demande a été faite (en-tête Origin), si elle est
 * fiable : soit déclarée EXACTEMENT dans config/cors.php, soit, en développement local uniquement,
 * une adresse IP réellement portée par CE serveur (accès depuis un smartphone au réseau local).
 * Un client HTTP peut forger Origin : accepter un motif de réseau local arbitraire ferait pointer
 * un lien d'approbation vers un hôte contrôlé par l'attaquant (hameçonnage). Sinon : FRONTEND_URL.
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

        return app()->environment('local') && self::estOrigineDeCeServeur($origin);
    }

    /**
     * Vrai si l'origine est http://<une IP de cette machine>:<port du front>. L'attaquant ne peut pas
     * faire héberger son site sur l'IP du serveur : le lien reste donc sur la machine de l'utilisateur.
     */
    private static function estOrigineDeCeServeur(string $origin): bool
    {
        $parties = parse_url($origin);
        if (! is_array($parties) || ($parties['scheme'] ?? '') !== 'http') {
            return false;
        }

        $hote = (string) ($parties['host'] ?? '');
        if (filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $front = parse_url((string) config('dgi.frontend_url', 'http://localhost:5173'));
        $portAttendu = (int) ($front['port'] ?? 80);
        if ((int) ($parties['port'] ?? 80) !== $portAttendu) {
            return false;
        }

        return in_array($hote, self::adressesLocales(), true);
    }

    /** @return array<int, string> adresses IPv4 portées par cette machine */
    private static function adressesLocales(): array
    {
        $adresses = [];

        if (function_exists('net_get_interfaces')) {
            foreach (net_get_interfaces() ?: [] as $interface) {
                foreach ((array) ($interface['unicast'] ?? []) as $u) {
                    $ip = (string) ($u['address'] ?? '');
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $adresses[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($adresses));
    }
}
