<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Centre;
use App\Services\DoublonCentre;
use App\Support\Noms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Gestion des centres (routes réservées à superadmin et central). */
class CentreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Centre::withCount('users');

        // Par défaut : uniquement les centres proposés (scope « proposes ») — un service central
        // masqué n'apparaît donc dans aucun filtre ni champ de recherche (archives, défaillances,
        // tableau de bord). La page de gestion demande la liste complète avec ?tous=1 pour que le
        // super admin retrouve le centre masqué puisse le réafficher.
        if (! $request->boolean('tous')) {
            $query->proposes();
        }

        return response()->json($query->orderBy('nom')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:centres,nom'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'type_centre' => ['nullable', Rule::in(['-', Centre::TYPE_CENTRAL])],
            'affiche' => ['nullable', 'boolean'],
        ], [
            'nom.required' => 'Le nom du centre est obligatoire.',
            'nom.unique' => 'Un centre porte déjà ce nom.',
        ]);

        // Refuse un centre qui désigne déjà une entité existante, même écrit autrement
        // (« DGE » face à « Direction des Grandes Entreprises (DGE) »).
        $this->refuserDoublon($data['nom']);

        // Seul le super admin choisit le type et l'affichage : un centre créé par un autre rôle reste fiscal.
        if (! $this->estSuperadmin($request)) {
            unset($data['type_centre'], $data['affiche']);
        }

        $data['type_centre'] = $data['type_centre'] ?? '-';
        $data['affiche'] = $data['type_centre'] === Centre::TYPE_CENTRAL ? ($data['affiche'] ?? true) : true;

        return response()->json(Centre::create($data), 201);
    }

    public function update(Request $request, Centre $centre): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255', Rule::unique('centres', 'nom')->ignore($centre->id)],
            'adresse' => ['nullable', 'string', 'max:255'],
            'type_centre' => ['nullable', Rule::in(['-', Centre::TYPE_CENTRAL])],
            'affiche' => ['nullable', 'boolean'],
        ], [
            'nom.required' => 'Le nom du centre est obligatoire.',
            'nom.unique' => 'Un centre porte déjà ce nom.',
        ]);

        // Le contrôle ne porte que si le nom change : un doublon déjà présent en base
        // reste modifiable (adresse, type, affichage) sans être bloqué.
        if (Noms::normaliser($data['nom']) !== Noms::normaliser($centre->nom)) {
            $this->refuserDoublon($data['nom'], $centre->id);
        }

        // Seul le super admin peut changer le type et l'affichage ; les autres rôles modifient le nom et l'adresse.
        if (! $this->estSuperadmin($request)) {
            unset($data['type_centre'], $data['affiche']);
        }

        $type = $data['type_centre'] ?? $centre->type_centre;
        $data['type_centre'] = $type;
        // Un centre fiscal est toujours proposé : repasser un service central en centre fiscal le réaffiche.
        $data['affiche'] = $type === Centre::TYPE_CENTRAL ? ($data['affiche'] ?? $centre->affiche) : true;

        $centre->update($data);

        return response()->json($centre);
    }

    private function estSuperadmin(Request $request): bool
    {
        return $request->user()?->role === Role::Superadmin;
    }

    /**
     * Refuse un centre qui désigne déjà une entité existante, même écrit différemment.
     * Le message indique le centre trouvé pour que l'utilisateur le sélectionne au lieu d'en créer un second.
     */
    private function refuserDoublon(string $nom, ?int $ignorerId = null): void
    {
        $existant = DoublonCentre::trouver($nom, null, $ignorerId);

        if ($existant !== null) {
            throw ValidationException::withMessages([
                'nom' => "Un centre équivalent existe déjà : « {$existant->nom} ». Utilisez ce centre au lieu d'en créer un nouveau.",
            ]);
        }
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
