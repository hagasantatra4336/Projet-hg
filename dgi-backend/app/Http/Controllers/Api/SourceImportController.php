<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\ControleAffectationSource;
use App\Services\AnomalieAnalyseur;
use App\Services\SourceDeclarationImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Récupération des données depuis les bases sources (superadmin, central, admin).
 * L'admin est limité à son propre centre. La base source n'est jamais modifiée.
 */
class SourceImportController extends Controller
{
    /** Vérifie la connexion à la base source (nom de la base, version, nombre de lignes). */
    public function tester(SourceDeclarationImporter $importer): JsonResponse
    {
        try {
            return response()->json($importer->tester());
        } catch (Throwable $e) {
            return $this->echecSource($e);
        }
    }

    /** Importe le référentiel officiel des centres dans la base de l'application. */
    public function importerCentres(SourceDeclarationImporter $importer): JsonResponse
    {
        try {
            $stats = $importer->importerCentres();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return $this->echecSource($e);
        }

        return response()->json($stats + [
            'message' => sprintf(
                '%d centre(s) lus : %d créé(s), %d mis à jour.',
                $stats['lues'],
                $stats['crees'],
                $stats['mis_a_jour'],
            ),
        ]);
    }

    /** Recherche une fiche du personnel par IM sans exposer les autres colonnes de la source. */
    public function agentParIm(Request $request, string $im, ControleAffectationSource $source): JsonResponse
    {
        try {
            $agent = $source->rechercherParIm($im);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return $this->echecSource($e);
        }

        if ($agent === null) {
            return response()->json(['message' => 'Aucun agent ne correspond à ce matricule IM.'], 404);
        }

        if ($request->user()->role === Role::Admin && $agent['centre_id'] !== $request->user()->centre_id) {
            return response()->json(['message' => 'Aucun agent ne correspond à ce matricule IM.'], 404);
        }

        return response()->json($agent);
    }

    /** Importe les déclarations de la base source puis, si demandé, lance l'analyse. */
    public function store(Request $request, SourceDeclarationImporter $importer): JsonResponse
    {
        $data = $request->validate([
            // Années fiscales « AAAA » (incluses) ; sans valeur : tout l'historique
            'depuis' => ['nullable', 'regex:' . SourceDeclarationImporter::ANNEE_REGEX],
            'jusqua' => ['nullable', 'regex:' . SourceDeclarationImporter::ANNEE_REGEX],
            'analyser' => ['sometimes', 'boolean'],
        ]);

        $me = $request->user();
        $centreId = null;

        if ($me->role === Role::Admin) {
            abort_if($me->centre_id === null, 403, "Votre compte n'est rattaché à aucun centre.");
            $centreId = $me->centre_id;
        }

        try {
            $stats = $importer->importer($data['depuis'] ?? null, $data['jusqua'] ?? null, $centreId);
        } catch (InvalidArgumentException $e) {
            // Configuration incomplète (ex. import limité à un centre sans colonne « centre ») : message sans secret, utile à l'utilisateur
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return $this->echecSource($e);
        }

        $analyse = null;
        if ($request->boolean('analyser')) {
            // Toutes les règles : défaillance de déclaration + baisse du chiffre d'affaires
            $analyse = app(AnomalieAnalyseur::class)->analyser(AnomalieAnalyseur::aujourdhui(), $centreId);
        }

        return response()->json($stats + ['analyse' => $analyse, 'message' => sprintf(
            '%d déclaration(s) lue(s) : %d importée(s), %d rejetée(s)%s.%s',
            $stats['lues'],
            $stats['importees'],
            $stats['rejetees'],
            $stats['ignorees'] > 0 ? sprintf(', %d ignorée(s) (autre centre)', $stats['ignorees']) : '',
            $analyse ? sprintf(
                " Analyse : %d nouvelle(s) alerte(s) (%d défaillance(s), %d baisse(s) du chiffre d'affaires), %d mise(s) à jour, %d régularisée(s).",
                $analyse['crees'],
                $analyse['defaillance']['crees'],
                $analyse['baisse_ca']['crees'],
                $analyse['mis_a_jour'],
                $analyse['regularisees']
            ) : ''
        )]);
    }

    /** Détail technique dans les logs seulement : le message de l'exception peut contenir hôte et identifiants. */
    private function echecSource(Throwable $e): JsonResponse
    {
        Log::error('Lecture des bases sources impossible : ' . $e->getMessage());

        return response()->json([
            'message' => "Impossible de lire les bases sources. Vérifiez les paramètres SOURCE_*_DB_* (détails dans les logs du serveur).",
        ], 502);
    }
}
