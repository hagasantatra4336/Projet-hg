<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Lance toutes les règles de détection d'anomalies (défaillance de déclaration + baisse du chiffre d'affaires)
 * à une date donnée (aujourd'hui par défaut) et additionne leurs résultats. Appelé par le bouton
 * « Lancer l'analyse », la commande planifiée et les imports.
 * Pour ajouter une règle : créer son détecteur et l'appeler ici.
 */
class AnomalieAnalyseur
{
    /** Clé de cache : date/heure et date de référence de la dernière analyse (affichées dans la page Anomalies). */
    public const CACHE_DERNIERE_ANALYSE = 'dgi.anomalies.derniere_analyse';

    public function __construct(
        private readonly DefaillanceDetector $defaillances,
        private readonly BaisseCaDetector $baissesCa,
    ) {
    }

    /** Date d'analyse par défaut : aujourd'hui. */
    public static function aujourdhui(): DateTimeImmutable
    {
        return new DateTimeImmutable(now()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    public function analyser(?DateTimeInterface $date = null, ?int $centreId = null): array
    {
        $date ??= self::aujourdhui();

        $d = $this->defaillances->analyser($date, $centreId);
        $c = $this->baissesCa->analyser($date, $centreId);

        Cache::forever(self::CACHE_DERNIERE_ANALYSE, ['at' => now()->toIso8601String(), 'date' => $date->format('Y-m-d')]);

        return [
            'date' => $date->format('Y-m-d'),
            'defaillance' => $d,
            'baisse_ca' => $c,
            'analyses' => $d['analyses'] + $c['analyses'],
            'crees' => $d['crees'] + $c['crees'],
            'mis_a_jour' => $d['mis_a_jour'] + $c['mis_a_jour'],
            'regularisees' => $d['regularisees'] + $c['regularisees'],
        ];
    }
}
