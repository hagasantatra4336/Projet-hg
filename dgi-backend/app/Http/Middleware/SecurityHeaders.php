<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité appliqués à toutes les réponses de l'application.
 * Le front statique est servi par nginx en production : les mêmes en-têtes doivent y être
 * ajoutés pour les pages HTML (voir docs/securite-deploiement.md).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Interdit l'interprétation d'un type MIME différent de celui annoncé (XSS via upload).
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Aucune page de l'application ne doit être affichée dans un cadre : anti-clickjacking.
        $response->headers->set('X-Frame-Options', 'DENY');
        // Ne transmet pas l'URL (qui peut contenir un jeton) aux sites externes.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Les réponses de l'API ne chargent et ne cadrent aucune ressource.
        if ($request->is('api/*')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        }

        // HSTS uniquement en HTTPS (derrière un proxy de confiance, isSecure() lit X-Forwarded-Proto).
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
