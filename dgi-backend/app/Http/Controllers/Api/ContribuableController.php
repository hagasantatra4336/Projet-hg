<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribuable;
use App\Services\FicheContribuable;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
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

        $avant = $contribuable->only(array_keys($donnees));
        $contribuable->update($donnees);

        // Trace dans le journal de la fiche : une modification de coordonnées ne doit jamais être
        // silencieuse (elle change les données de contact officielles du contribuable).
        $libelles = ['telephone' => 'téléphone', 'email' => 'e-mail', 'adresse' => 'adresse', 'activite' => 'activité'];
        $changements = [];

        foreach ($donnees as $champ => $apres) {
            $a = (string) ($avant[$champ] ?? '');
            $b = (string) ($apres ?? '');

            if ($a !== $b) {
                $changements[] = $libelles[$champ] . ' « ' . ($a === '' ? '—' : $a) . ' » → « ' . ($b === '' ? '—' : $b) . ' »';
            }
        }

        if ($changements !== []) {
            $contribuable->observations()->create([
                'user_id' => $request->user()->id,
                'texte' => 'Coordonnées mises à jour — ' . implode(' ; ', $changements) . '.',
            ]);
        }

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

    /**
     * Export PDF : le contenu s'écoule sur une seule page A4 quand il y tient, sinon sur autant
     * de pages que nécessaire. Le nombre de pages n'est fixé nulle part : dompdf coupe entre les
     * blocs, sans jamais couper une ligne de tableau ni une observation.
     */
    public function pdf(Request $request, Contribuable $contribuable): Response
    {
        $pdf = Pdf::loadView('pdf.fiche-contribuable', $this->donneesVue($request, $contribuable))
            ->setPaper('a4');

        $pdf->render();

        // Pied de page numéroté (« page 1 / 3 ») et rappel de la fiche en tête des pages suivantes.
        $canvas = $pdf->getCanvas();
        $police = $pdf->getFontMetrics()->getFont('times', 'normal');
        $gris = [0.27, 0.27, 0.27];
        $marge = 14 * 72 / 25.4;              // marge de 14 mm du gabarit, en points
        $droite = 595.276 - $marge;           // largeur d'une page A4 en points, moins la marge
        $entete = "Fiche de résultat de l'analyse des risques fiscaux — NIF " . $contribuable->nif;
        $pied = 'Fiche n° ' . str_pad((string) $contribuable->id, 4, '0', STR_PAD_LEFT)
            . ' — NIF ' . $contribuable->nif . ' — page %d / %d';

        $canvas->page_script(function (int $pageNumber, int $pageCount, Canvas $canvasPage, FontMetrics $metrics) use ($police, $gris, $marge, $droite, $entete, $pied): void {
            if ($pageNumber > 1) {
                $canvasPage->text($marge, 26, $entete, $police, 7, $gris);
                $canvasPage->line($marge, 31, $droite, 31, $gris, 0.5);
            }

            $texte = sprintf($pied, $pageNumber, $pageCount);
            $canvasPage->text($droite - $metrics->getTextWidth($texte, $police, 7), 812, $texte, $police, 7, $gris);
        });

        return $pdf->download('fiche-' . $contribuable->nif . '.pdf');
    }

    /** Aperçu de la fiche : même gabarit que le PDF, rendu en HTML pour l'écran (sans lecteur PDF). */
    public function html(Request $request, Contribuable $contribuable): Response
    {
        return response()->view('pdf.fiche-contribuable', $this->donneesVue($request, $contribuable));
    }

    /** Données communes aux deux rendus (PDF et HTML) du même gabarit. */
    private function donneesVue(Request $request, Contribuable $contribuable): array
    {
        $me = $request->user();
        $this->fiche->autoriser($me, $contribuable);

        return [
            'fiche' => $this->fiche->donnees($contribuable, $me),
            'auteur' => $me->nom,
            'fonction' => $me->fonction?->nom,
            'genereLe' => now(),
            'logo' => $this->logo(),
        ];
    }

    /** Logo DGI encodé en data URI : même image dans le PDF (dompdf) et dans l'aperçu HTML. */
    private function logo(): ?string
    {
        $chemin = public_path('logo-dgi.png');

        return is_file($chemin) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($chemin)) : null;
    }
}
