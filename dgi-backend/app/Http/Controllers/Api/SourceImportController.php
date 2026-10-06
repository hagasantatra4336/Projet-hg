<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\AnomalieAnalyseur;
use App\Services\DefaillanceDetector;
use App\Services\SourceDeclarationImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Récupération des déclarations depuis la base PostgreSQL source (superadmin, central, admin).
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

    /** Importe les déclarations de la base source puis, si demandé, lance l'analyse. */
    public function store(Request $request, SourceDeclarationImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'depuis' => ['nullable', 'regex:' . SourceDeclarationImporter::PERIODE_REGEX],
            'jusqua' => ['nullable', 'regex:' . SourceDeclarationImporter::PERIODE_REGEX],
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
        } catch (Throwable $e) {
            return $this->echecSource($e);
        }

        $analyse = null;
        if ($request->boolean('analyser')) {
            // Toutes les règles : défaillance de déclaration + baisse du chiffre d'affaires
            $analyse = app(AnomalieAnalyseur::class)->analyser($data['jusqua'] ?? DefaillanceDetector::periodePrecedente(), $centreId);
            // Compatibilité : nombre total d'éléments analysés (clé « analyses » d'avant)
            $analyse['analyses'] = $analyse['defaillance']['analyses'] + $analyse['baisse_ca']['analyses'];
        }

        return response()->json($stats + ['analyse' => $analyse, 'message' => sprintf(
            '%d ligne(s) lue(s) : %d importée(s), %d rejetée(s).%s',
            $stats['lues'],
            $stats['importees'],
            $stats['rejetees'],
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
        Log::error('Base source PostgreSQL : ' . $e->getMessage());

        return response()->json([
            'message' => "Impossible de lire la base source. Vérifiez la configuration SOURCE_DB_* (détails dans les logs du serveur).",
        ], 502);
    }
}
