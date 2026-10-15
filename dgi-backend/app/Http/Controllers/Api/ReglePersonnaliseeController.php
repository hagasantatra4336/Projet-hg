<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alerte;
use App\Models\Indicateur;
use App\Models\Regle;
use App\Models\RegleHistorique;
use App\Models\RegleSimulation;
use App\Services\EvaluateurConditions;
use App\Services\ModelesRegles;
use App\Services\ReglesPersonnalisees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Règles personnalisées (central, superadmin) : création à partir d'un modèle, modification, simulation, activation.
 * La logique est dans App\Services\ReglesPersonnalisees ; ce contrôleur valide les entrées et présente les résultats.
 */
class ReglePersonnaliseeController extends Controller
{
    public function __construct(private readonly ReglesPersonnalisees $service)
    {
    }

    /** Listes pour les formulaires : modèles, indicateurs, impôts, niveaux, mesures, comparateurs. */
    public function options(): JsonResponse
    {
        $this->verifierInstallation();

        return response()->json([
            'modeles' => ModelesRegles::catalogue(),
            'indicateurs' => Indicateur::where('actif', true)->orderBy('libelle')->get(['code', 'libelle', 'unite']),
            'impots' => array_values((array) config('anomalies.defaillance.impots')),
            'niveaux' => ReglesPersonnalisees::NIVEAUX,
            'mesures' => EvaluateurConditions::MESURES,
            'comparateurs' => EvaluateurConditions::COMPARATEURS,
            'max_conditions' => EvaluateurConditions::MAX_CONDITIONS,
            'max_profondeur' => EvaluateurConditions::MAX_PROFONDEUR,
        ]);
    }

    public function index(): JsonResponse
    {
        $this->verifierInstallation();

        $regles = Regle::personnalisees()->with(['modifiePar', 'creePar'])->orderByDesc('id')->get()
            ->map(fn (Regle $r) => $this->present($r, false))->values();

        return response()->json(['regles' => $regles]);
    }

    public function show(Regle $regle): JsonResponse
    {
        $this->verifierInstallation();

        return response()->json($this->present($this->personnalisee($regle), true));
    }

    public function store(Request $request): JsonResponse
    {
        $this->verifierInstallation();

        $data = $request->validate([
            'modele' => ['required', Rule::in(ModelesRegles::CODES)],
            'nom' => ['required', 'string', 'max:150'],
            'parametres' => ['required', 'array'],
            'impots' => ['required', 'array', 'min:1'],
            'impots.*' => ['string'],
            'niveau' => ['nullable', Rule::in(ReglesPersonnalisees::NIVEAUX)],
        ], [], ['nom' => 'nom', 'impots' => 'impôts']);

        $regle = $this->service->creerDepuisModele($request->user(), $data['modele'], $data['nom'], $data['parametres'], $data['impots'], $data['niveau'] ?? null);

        return response()->json($this->present($regle, true), 201);
    }

    public function update(Request $request, Regle $regle): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:150'],
            'conditions' => ['sometimes', 'array'],
            'impots' => ['sometimes', 'array', 'min:1'],
            'impots.*' => ['string'],
            'niveau' => ['sometimes', Rule::in(ReglesPersonnalisees::NIVEAUX)],
            'fenetre_annees' => ['sometimes', 'integer', 'between:0,5'],
        ]);

        $regle = $this->service->mettreAJour($this->personnalisee($regle), $request->user(), $data);

        return response()->json($this->present($regle, true));
    }

    public function simuler(Request $request, Regle $regle): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $regle = $this->personnalisee($regle);
        $this->service->simuler(
            $regle,
            $request->user(),
            isset($data['date']) ? new \DateTimeImmutable($data['date']) : null,
            $data['centre_id'] ?? null
        );

        return response()->json($this->present($regle->fresh(), true));
    }

    public function activer(Request $request, Regle $regle): JsonResponse
    {
        return response()->json($this->present($this->service->activer($this->personnalisee($regle), $request->user()), true));
    }

    public function desactiver(Request $request, Regle $regle): JsonResponse
    {
        return response()->json($this->present($this->service->desactiver($this->personnalisee($regle), $request->user()), true));
    }

    public function destroy(Regle $regle): JsonResponse
    {
        $this->service->supprimer($this->personnalisee($regle));

        return response()->json(['message' => 'Règle supprimée.']);
    }

    // ------------------------------------------------------------------

    private function verifierInstallation(): void
    {
        abort_unless(
            Schema::hasTable('indicateurs') && Schema::hasTable('regles_simulations') && Schema::hasColumn('regles', 'conditions'),
            503,
            "Les règles personnalisées ne sont pas encore installées : lancez « php artisan migrate » dans le dossier backend, puis « php artisan config:clear »."
        );
    }

    private function personnalisee(Regle $regle): Regle
    {
        abort_unless($regle->estPersonnalisee(), 404, 'Règle personnalisée introuvable.');

        return $regle;
    }

    /** @return array<string, mixed> */
    private function present(Regle $r, bool $detail): array
    {
        $valide = $this->service->simulationValide($r);
        $derniere = $r->simulations()->latest('id')->first();

        $base = [
            'id' => $r->id,
            'code' => $r->code,
            'nom' => $r->nom,
            'description' => $r->description,
            'modele' => $r->modele,
            'statut' => $r->statut,
            'actif' => (bool) $r->actif,
            'niveau' => $r->niveau,
            'impots' => $r->impots,
            'conditions' => $r->conditions,
            'fenetre_annees' => (int) ($r->parametres['fenetre_annees'] ?? 1),
            'version' => $r->version,
            'simulation_valide' => $valide !== null,           // l'activation est possible
            'derniere_simulation' => $derniere ? $this->presentSimulation($derniere, false) : null,
            'nb_alertes' => Alerte::where('regle', $r->code)->count(),
            'cree_par' => $r->creePar?->nom,
            'modifie_par' => $r->modifiePar?->nom,
            'modifie_le' => $r->updated_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $base;
        }

        $base['simulation'] = $derniere ? $this->presentSimulation($derniere, true) : null;
        $base['historique'] = $r->historique()->with('user')->latest('id')->limit(15)->get()->map(fn (RegleHistorique $h) => [
            'id' => $h->id,
            'date' => $h->created_at?->toIso8601String(),
            'par' => $h->user?->nom,
            'action' => $h->action,
            'avant' => $h->avant,
            'apres' => $h->apres,
        ])->values();

        return $base;
    }

    /** @return array<string, mixed> */
    private function presentSimulation(RegleSimulation $s, bool $avecResultats): array
    {
        return [
            'id' => $s->id,
            'date' => $s->created_at?->toIso8601String(),
            'date_analyse' => $s->date_analyse?->toDateString(),
            'version' => $s->version,
            'statut' => $s->statut,
            'erreur' => $s->erreur,
            'a_jour' => $s->empreinte === $s->regle?->empreinte, // simulation de la définition actuelle
            'alertes' => $s->resultats['alertes'] ?? null,
            'analyses' => $s->resultats['analyses'] ?? null,
            'resultats' => $avecResultats ? $s->resultats : null,
        ];
    }
}
