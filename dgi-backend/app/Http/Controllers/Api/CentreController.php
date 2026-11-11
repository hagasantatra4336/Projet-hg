<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Centre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Gestion des centres (routes réservées à superadmin et central). */
class CentreController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Centre::withCount('users')->orderBy('nom')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:centres,nom'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(Centre::create($data), 201);
    }

    public function update(Request $request, Centre $centre): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255', Rule::unique('centres', 'nom')->ignore($centre->id)],
            'adresse' => ['nullable', 'string', 'max:255'],
        ]);

        $centre->update($data);

        return response()->json($centre);
    }

    /**
     * Affiche ou masque un service central dans l'inscription et les listes de choix (super admin uniquement).
     * Le centre n'est jamais supprimé : ses utilisateurs et ses dossiers restent inchangés.
     */
    public function affichage(Request $request, Centre $centre): JsonResponse
    {
        abort_unless(
            $centre->type_centre === Centre::TYPE_CENTRAL,
            422,
            "Seul un centre de type central peut être affiché ou masqué."
        );

        $data = $request->validate([
            'affiche' => ['present', 'boolean'],
        ]);

        $centre->update(['affiche' => (bool) $data['affiche']]);

        return response()->json($centre);
    }

    public function destroy(Centre $centre): JsonResponse
    {
        $count = $centre->users()->count();

        if ($count > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$count} utilisateur(s) sont rattachés à ce centre.",
            ], 422);
        }

        $centre->delete();

        return response()->json(['message' => 'Centre supprimé.']);
    }
}
