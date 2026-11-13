<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé ne conserve aucun accès, même avec un jeton encore valide.
 * La réponse porte le motif, affiché à l'utilisateur renvoyé vers la connexion.
 */
class RejeterCompteDesactive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->estDesactive()) {
            $user->tokens()->delete();

            return response()->json($user->payloadDesactivation(), 401);
        }

        return $next($request);
    }
}
