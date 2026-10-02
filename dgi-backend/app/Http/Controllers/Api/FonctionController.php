<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Fonction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des fonctions et du rôle associé à chacune (superadmin uniquement).
 * Le rôle d'un utilisateur = le rôle de sa fonction.
 */
class FonctionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Fonction::withCount('users')->orderBy('nom')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:fonctions,nom'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        return response()->json(Fonction::create($data), 201);
    }

    public function update(Request $request, Fonction $fonction): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255', Rule::unique('fonctions', 'nom')->ignore($fonction->id)],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        // Évite de se retirer ses propres droits par erreur.
        if (
            $fonction->id === $request->user()->fonction_id
            && $data['role'] !== $fonction->role->value
        ) {
            throw ValidationException::withMessages([
                'role' => 'Vous ne pouvez pas modifier le rôle de votre propre fonction.',
            ]);
        }

        $fonction->update($data);

        return response()->json($fonction);
    }

    public function destroy(Fonction $fonction): JsonResponse
    {
        $count = $fonction->users()->count();

        if ($count > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$count} utilisateur(s) ont cette fonction.",
            ], 422);
        }

        $fonction->delete();

        return response()->json(['message' => 'Fonction supprimée.']);
    }
}
