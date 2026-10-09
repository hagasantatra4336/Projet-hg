<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyseAutomatique;
use Illuminate\Http\JsonResponse;
use Throwable;

/** POST /api/defaillances/auto : appelé toutes les 5 minutes par le front tant qu'un utilisateur est connecté. */
class AnalyseAutoController extends Controller
{
    public function __invoke(AnalyseAutomatique $auto): JsonResponse
    {
        set_time_limit(300);      // la récupération peut dépasser les 30 s par défaut de PHP
        ignore_user_abort(true);  // fermer l'onglet ne doit pas couper l'analyse en plein milieu

       try {
    $r = $auto->lancer();

    // Réponse minimale : l'analyse porte sur tous les centres, un agent n'a pas à en voir les chiffres.
    return response()->json(['execute' => $r['execute'], 'raison' => $r['raison'] ?? null]);
    } catch (Throwable $e) {
    report($e);

    return response()->json([
        'execute' => false,
        'message' => "Analyse automatique impossible (source externe injoignable ?). Voir storage/logs/laravel.log.",
    ], 502);
    } catch (Throwable $e) {
            report($e);

            return response()->json([
                'execute' => false,
                'message' => "Analyse automatique impossible (source externe injoignable ?). Voir storage/logs/laravel.log.",
            ], 502);
        }
    }
}
