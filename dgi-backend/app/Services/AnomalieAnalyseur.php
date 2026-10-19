<?php

namespace App\Services;

use App\Models\Regle;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lance toutes les règles de détection d'anomalies (défaillance de déclaration, baisse du chiffre d'affaires, règles personnalisées actives,
 * critères de risque R1, R2, R3, R5, R6, R7, à une date donnée (aujourd'hui par défaut) et additionne leurs résultats. Appelé par le bouton
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
        private readonly DetecteurPersonnalise $personnalisees,
        private readonly DetecteurRisque $risque,
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

        // Critères de risque R1, R2, R3, R5, R6, R7 (base d'analyse risque). Une base vide n'a aucun effet.
        $r = $this->risque->analyser($date, $centreId);

        // Règles personnalisées ACTIVES (brouillons et règles désactivées sont ignorés). Une règle en erreur
        // (colonne supprimée, indicateur désactivé...) ne bloque ni les autres règles ni l'analyse.
        $p = ['analyses' => 0, 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0];
        $detailPerso = [];
        foreach (ReglesDetection::personnalisees() as $regle) {
            if ($regle->statut !== Regle::ACTIVE || ! $regle->actif) {
                continue;
            }
            try {
                $r = $this->personnalisees->analyser($regle, $date, $centreId);
            } catch (Throwable $e) {
                report($e);
                $detailPerso[$regle->code] = ['erreur' => $e->getMessage()];
                continue;
            }
            $detailPerso[$regle->code] = $r;
            foreach ($p as $k => $v) {
                $p[$k] += $r[$k];
            }
        }

        Cache::forever(self::CACHE_DERNIERE_ANALYSE, ['at' => now()->toIso8601String(), 'date' => $date->format('Y-m-d')]);

        return [
            'date' => $date->format('Y-m-d'),
            'defaillance' => $d,
            'baisse_ca' => $c,
            'desactivees' => array_keys(array_filter(['defaillance' => $d['desactivee'] ?? false, 'baisse_ca' => $c['desactivee'] ?? false])),
            'personnalisees' => $detailPerso,
            'risque' => $r,
            'analyses' => $d['analyses'] + $c['analyses'] + $p['analyses'] + $r['analyses'],
            'crees' => $d['crees'] + $c['crees'] + $p['crees'] + $r['crees'],
            'mis_a_jour' => $d['mis_a_jour'] + $c['mis_a_jour'] + $p['mis_a_jour'] + $r['mis_a_jour'],
            'regularisees' => $d['regularisees'] + $c['regularisees'] + $p['regularisees'] + $r['regularisees'],
        ];
    }
}
