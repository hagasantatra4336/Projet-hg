<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Centre;
use App\Models\Fonction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des utilisateurs (routes réservées à superadmin, central et admin).
 *
 * Règles :
 *  - superadmin : gère tout le monde, attribue toutes les fonctions ;
 *  - central    : gère tous les centres, mais uniquement les rôles de rang inférieur (admin, agent) ;
 *  - admin      : ne voit et ne gère que les agents de SON centre.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $query = User::with(['centre', 'fonction'])->orderBy('nom');

        if ($me->role === Role::Admin) {
            $query->where('centre_id', $this->ownCentreId($me));
        } elseif ($request->filled('centre_id')) {
            $query->where('centre_id', (int) $request->input('centre_id'));
        }

        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';

            // ILIKE : insensible à la casse (PostgreSQL)
            $query->where(function ($q) use ($term) {
                $q->where('nom', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('im', 'ilike', $term)
                    ->orWhere('telephone', 'ilike', $term);
            });
        }

        $page = $query->paginate(10)
            ->through(fn (User $u) => (new UserResource($u))->resolve());

        return response()->json($page);
    }

    /** Listes utilisées par le formulaire : centres et fonctions que l'utilisateur connecté peut attribuer. */
    public function options(Request $request): JsonResponse
    {
        $me = $request->user();

        $centres = Centre::orderBy('nom')->get(['id', 'nom', 'adresse']);
        if ($me->role === Role::Admin) {
            $ownId = $this->ownCentreId($me);
            $centres = $centres->where('id', $ownId)->values();
        }

        $fonctions = Fonction::orderBy('nom')->get()
            ->filter(fn (Fonction $f) => $me->role->canAssign($f->role))
            ->map(fn (Fonction $f) => ['id' => $f->id, 'nom' => $f->nom, 'role' => $f->role->value])
            ->values();

        return response()->json(['centres' => $centres, 'fonctions' => $fonctions]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate($this->rules());

        $fonction = Fonction::findOrFail($data['fonction_id']);
        $this->assertCanAssign($me, $fonction);
        $data['centre_id'] = $this->resolveCentreId($me, $fonction, $data['centre_id'] ?? null);

        // Compte créé par un responsable : e-mail considéré comme vérifié.
        $user = User::create($data + ['email_verified_at' => now()]);
        $user->load(['centre', 'fonction']);

        return response()->json((new UserResource($user))->resolve(), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $this->assertCanManage($me, $user);

        $data = $request->validate($this->rules($user));
        $fonction = Fonction::findOrFail($data['fonction_id']);

        if ($user->id === $me->id) {
            if ((int) $data['fonction_id'] !== $user->fonction_id) {
                throw ValidationException::withMessages([
                    'fonction_id' => 'Vous ne pouvez pas modifier votre propre fonction.',
                ]);
            }
        } else {
            $this->assertCanAssign($me, $fonction);
        }

        $data['centre_id'] = $this->resolveCentreId($me, $fonction, $data['centre_id'] ?? null);

        $passwordChanged = ! empty($data['password']);
        if (! $passwordChanged) {
            unset($data['password']);
        }

        $user->update($data);

        if ($passwordChanged) {
            $user->tokens()->delete(); // déconnecte les sessions existantes
        }

        $user->load(['centre', 'fonction']);

        return response()->json((new UserResource($user))->resolve());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        abort_if($user->id === $me->id, 422, 'Vous ne pouvez pas supprimer votre propre compte.');
        $this->assertCanManage($me, $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    // ------------------------------------------------------------------
    //  Outils internes
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function rules(?User $user = null): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'im' => ['nullable', 'string', 'max:50', Rule::unique('users', 'im')->ignore($user?->id)],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'fonction_id' => ['required', 'integer', 'exists:fonctions,id'],
            // obligatoire à la création, facultatif à la modification
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
        ];
    }

    private function ownCentreId(User $me): int
    {
        abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");

        return $me->centre_id;
    }

    /** Un admin travaille toujours dans son centre ; agent/admin doivent avoir un centre. */
    private function resolveCentreId(User $me, Fonction $fonction, ?int $centreId): ?int
    {
        if ($me->role === Role::Admin) {
            return $this->ownCentreId($me);
        }

        if ($centreId === null && in_array($fonction->role, [Role::Agent, Role::Admin], true)) {
            throw ValidationException::withMessages([
                'centre_id' => 'Le centre est obligatoire pour cette fonction.',
            ]);
        }

        return $centreId;
    }

    private function assertCanAssign(User $me, Fonction $fonction): void
    {
        if (! $me->role->canAssign($fonction->role)) {
            throw ValidationException::withMessages([
                'fonction_id' => "Vous ne pouvez pas attribuer cette fonction.",
            ]);
        }
    }

    private function assertCanManage(User $me, User $target): void
    {
        if ($me->role === Role::Superadmin) {
            return;
        }

        $targetRank = $target->role?->rank() ?? 0;

        abort_if(
            $targetRank >= $me->role->rank(),
            403,
            "Vous n'avez pas le droit de gérer cet utilisateur."
        );

        abort_if(
            $me->role === Role::Admin && $target->centre_id !== $this->ownCentreId($me),
            403,
            "Cet utilisateur n'appartient pas à votre centre."
        );
    }
}