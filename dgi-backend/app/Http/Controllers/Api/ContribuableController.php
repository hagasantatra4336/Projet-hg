<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribuable;
use App\Services\FicheContribuable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fiche contribuable : consultation (identité, coordonnées, obligations, alertes, historique, synthèse,
 * observations), mise à jour des coordonnées, ajout d'une observation et export PDF.
 * Le filtrage par centre est porté par FicheContribuable::autoriser().
 */
class ContribuableController extends Controller
{
    public function __construct(private readonly FicheContribuable $fiche)
    {
    }

    public function show(Request $request, Contribuable $contribuable): JsonResponse
    {
        $this->fiche->autoriser($request->user(), $contribuable);

        return response()->json($this->fiche->donnees($contribuable, $request->user()));
    }

    public function updateContact(Request $request, Contribuable $contribuable): JsonResponse
    {
        $this->fiche->autoriser($request->user(), $contribuable);

        $donnees = $request->validate([
            'telephone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'activite' => ['nullable', 'string', 'max:160'],
        ]);

        $contribuable->update($donnees);

        return response()->json($this->fiche->donnees($contribuable->fresh(), $request->user()));
    }

    public function storeObservation(Request $request, Contribuable $contribuable): JsonResponse
    {
        $this->fiche->autoriser($request->user(), $contribuable);

        $donnees = $request->validate([
            'texte' => ['required', 'string', 'max:2000'],
        ]);

        $contribuable->observations()->create([
            'user_id' => $request->user()->id,
            'texte' => $donnees['texte'],
        ]);

        return response()->json($this->fiche->donnees($contribuable->fresh(), $request->user()), 201);
    }

    public function pdf(Request $request, Contribuable $contribuable): Response
    {
        $me = $request->user();
        $this->fiche->autoriser($me, $contribuable);

        $pdf = Pdf::loadView('pdf.fiche-contribuable', [
            'fiche' => $this->fiche->donnees($contribuable, $me),
            'auteur' => $me->nom,
            'fonction' => $me->fonction?->nom,
            'genereLe' => now(),
        ])->setPaper('a4');

        return $pdf->download('fiche-' . $contribuable->nif . '.pdf');
    }
}
