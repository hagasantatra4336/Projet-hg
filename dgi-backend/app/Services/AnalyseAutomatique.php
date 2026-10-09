<?php

namespace App\Services;

use App\Services\SynchroniseurDeclarations;
use Illuminate\Support\Facades\Cache;

/**
 * Analyse automatique déclenchée par l'application ouverte (sans « php artisan schedule:work ») :
 * récupération des déclarations de la source externe (si configurée) puis détection des défaillances.
 *
 * Plusieurs utilisateurs / onglets peuvent la demander en même temps : un verrou évite les exécutions
 * simultanées et un délai minimum évite de relancer l'analyse plus d'une fois toutes les ~5 minutes.
 */
class AnalyseAutomatique
{
    public const INTERVALLE_SECONDES = 270; // un peu moins de 5 min, pour absorber le décalage des minuteurs
    private const CLE_DERNIERE = 'dgi.analyse-auto.derniere';
    private const CLE_VERROU = 'dgi.analyse-auto.verrou';

    /** @return array<string, mixed> */
    public function lancer(): array
    {
        $verrou = Cache::lock(self::CLE_VERROU, 600);

        if (! $verrou->get()) {
            return ['execute' => false, 'raison' => 'deja_en_cours'];
        }

        try {
            $derniere = (int) Cache::get(self::CLE_DERNIERE, 0);
            if (time() - $derniere < self::INTERVALLE_SECONDES) {
                return ['execute' => false, 'raison' => 'trop_recent'];
            }

            // Enregistré avant l'exécution : une source en panne n'est pas ré-interrogée à chaque appel.
            Cache::forever(self::CLE_DERNIERE, time());

            $synchronisation = null;
            if (class_exists(SynchroniseurDeclarations::class)) {
                $synchro = app(SynchroniseurDeclarations::class);
                if ($synchro->actif()) {
                    $synchronisation = $synchro->synchroniser(); // une exception ici empêche l'analyse (données non rafraîchies)
                }
            }

            $stats = (new DefaillanceDetector())->analyser(DefaillanceDetector::periodePrecedente());

            return ['execute' => true, 'synchronisation' => $synchronisation] + $stats;
        } finally {
            $verrou->release();
        }
    }
}
