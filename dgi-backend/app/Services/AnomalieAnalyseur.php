<?php

namespace App\Services;

/**
 * Lance toutes les règles de détection d'anomalies (défaillance de déclaration + baisse du chiffre d'affaires)
 * et additionne leurs résultats. Appelé par le bouton « Lancer l'analyse » et par la commande planifiée.
 * Pour ajouter une règle : créer son détecteur et l'appeler ici.
 */
class AnomalieAnalyseur
{
    public function __construct(
        private readonly DefaillanceDetector $defaillances,
        private readonly BaisseCaDetector $baissesCa,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyser(string $periode, ?int $centreId = null): array
    {
        $d = $this->defaillances->analyser($periode, $centreId);
        $c = $this->baissesCa->analyser($periode, $centreId);

        return [
            'periode' => $periode,
            'defaillance' => $d,
            'baisse_ca' => $c,
            'crees' => $d['crees'] + $c['crees'],
            'mis_a_jour' => $d['mis_a_jour'] + $c['mis_a_jour'],
            'regularisees' => $d['regularisees'] + $c['regularisees'],
        ];
    }
}
