<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute requête d'un compte désactivé, même avec un jeton encore valide.
 * La désactivation révoque déjà les jetons : ce contrôle couvre le cas d'un jeton émis
 * entre-temps (connexion simultanée) et ne dépend d'aucune autre règle.
 */
class EnsureCompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->estDesactive()) {
            $user->tokens()->delete();

            return response()->json($user->payloadDesactivation(), 401);
        }

        return $next($request);
    }
}
