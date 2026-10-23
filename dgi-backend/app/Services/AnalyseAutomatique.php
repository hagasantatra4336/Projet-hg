<?php

namespace App\Services;

use App\Services\SynchroniseurDeclarations;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Analyse automatique déclenchée par l'application ouverte (sans « php artisan schedule:work »).
 *
 * [MODIFIÉ] L'analyse n'est PLUS continue : elle ne rejuge les périodes qu'à l'ouverture d'une nouvelle
 * fenêtre d'échéance (date limite de paiement + 48 h, calendrier CalendrierFiscal). Entre deux échéances
 * les réponses sont « pas_echeance » : les déclarations peuvent encore être corrigées, les juger avant
 * l'échéance fausserait l'analyse. La SYNCHRONISATION de la source externe reste en revanche régulière.
 *
 * Plusieurs utilisateurs / onglets peuvent la demander en même temps : un verrou évite les exécutions
 * simultanées et un délai minimum évite de relancer l'analyse plus d'une fois toutes les ~5 minutes.
 */
class AnalyseAutomatique
{
    public const INTERVALLE_SECONDES = 270; // un peu moins de 5 min, pour absorber le décalage des minuteurs
    private const CLE_DERNIERE = 'dgi.analyse-auto.derniere';
    private const CLE_VERROU = 'dgi.analyse-auto.verrou';
    private const CLE_ECHEANCE = 'dgi.analyse-auto.echeance'; // [AJOUT] dernière fenêtre d'échéance déjà analysée

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

            // [AJOUT] Une nouvelle fenêtre d'échéance (date limite + 48 h) s'est-elle ouverte depuis la
            // dernière analyse ? Sinon : rien à rejuger, on répond « pas_echeance » sans toucher à la source.
            $echeance = CalendrierFiscal::derniereEcheancePassee(new DateTimeImmutable('now'));
            if ($echeance->getTimestamp() <= (int) Cache::get(self::CLE_ECHEANCE, 0)) {
                return ['execute' => false, 'raison' => 'pas_echeance'];
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

            // [CORRIGÉ] periodePrecedente() n'existe plus → analyse à la date du jour ; le détecteur
            // retient lui-même les seules périodes dont l'échéance + 48 h est passée (CalendrierFiscal).
            $stats = (new DefaillanceDetector())->analyser(AnomalieAnalyseur::aujourdhui());

            // [AJOUT] Fenêtre d'échéance consommée seulement APRÈS une analyse réussie : une panne
            // (source injoignable...) sera réessayée à l'appel suivant (~5 min), pas perdue pour le mois.
            Cache::forever(self::CLE_ECHEANCE, $echeance->getTimestamp());

            return ['execute' => true, 'synchronisation' => $synchronisation] + $stats;
        } finally {
            $verrou->release();
        }
    }
}
