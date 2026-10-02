<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Utilisation (sans enregistrer d'alias) :
 *   ->middleware(EnsureRole::class . ':superadmin,central')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;

        if (! $role || ! in_array($role->value, $roles, true)) {
            return response()->json(['message' => "Vous n'avez pas les droits nécessaires."], 403);
        }

        return $next($request);
    }
}
