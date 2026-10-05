<?php

namespace App\Services;

use App\Models\Alerte;
use App\Models\Declaration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Détection de la DÉFAILLANCE DE DÉCLARATION (EXF-004).
 *
 * Pour chaque couple (contribuable, impôt) :
 *   1. on part de la période de référence R (le mois qui aurait dû être déclaré) ;
 *   2. on compte les mois consécutifs SANS déclaration jusqu'à R  → "mois manqués" ;
 *   3. on compte les mois consécutifs DÉCLARÉS juste avant la rupture → "série" ;
 *   4. si mois manqués >= 1 et série >= serie_minimale (6 par défaut), une alerte est créée/mise à jour.
 *
 * Exemple : déclaré de janvier à juin, rien en juillet, R = juillet → alerte (1 mois manqué, série de 6).
 * Les seuils sont dans config/anomalies.php.
 */
class DefaillanceDetector
{
    /** Clé de cache : date/heure et période de la dernière analyse (affichées dans la page Défaillances). */
    public const CACHE_DERNIERE_ANALYSE = 'dgi.defaillance.derniere_analyse';

    private const MOIS = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /** Période de référence par défaut : le mois précédent (« AAAA-MM »). */
    public static function periodePrecedente(): string
    {
        return now()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * @return array{periode: string, analyses: int, crees: int, mis_a_jour: int, regularisees: int}
     */
    public function analyser(string $periode, ?int $centreId = null): array
    {
        $cfg = config('anomalies.defaillance');
        $ref = self::index($periode);

        $query = Declaration::query()
            ->join('contribuables', 'contribuables.id', '=', 'declarations.contribuable_id')
            ->whereIn('declarations.type_impot', $cfg['impots'])
            ->where('declarations.periode', '<=', $periode) // "AAAA-MM" se compare comme du texte
            ->orderBy('declarations.contribuable_id')
            ->orderBy('declarations.type_impot')
            ->orderBy('declarations.periode')
            ->select('declarations.contribuable_id', 'declarations.type_impot', 'declarations.periode');

        if ($centreId !== null) {
            $query->where('contribuables.centre_id', $centreId);
        }

        $stats = ['periode' => $periode, 'analyses' => 0, 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0];

        // 1) Lecture seule : on repère les défaillances (aucune écriture pendant le parcours).
        $trouvees = [];
        $courant = null;
        $periodes = [];

        foreach ($query->cursor() as $row) {
            $cle = $row->contribuable_id . '|' . $row->type_impot;

            if ($cle !== $courant) {
                if ($courant !== null) {
                    $stats['analyses']++;
                    $this->evaluer($courant, $periodes, $ref, $cfg, $trouvees);
                }
                $courant = $cle;
                $periodes = [];
            }

            $periodes[] = self::index($row->periode);
        }

        if ($courant !== null) {
            $stats['analyses']++;
            $this->evaluer($courant, $periodes, $ref, $cfg, $trouvees);
        }

        // 2) Écriture : création / mise à jour des alertes, puis régularisation de celles qui ne tiennent plus.
        DB::transaction(function () use ($trouvees, $periode, $centreId, &$stats) {
            $actuelles = [];

            foreach ($trouvees as $t) {
                $alerte = Alerte::firstOrNew([
                    'contribuable_id' => $t['contribuable_id'],
                    'regle' => Alerte::REGLE_DEFAILLANCE,
                    'type_impot' => $t['type_impot'],
                    'periode' => $t['periode'],
                ]);

                $existait = $alerte->exists;

                $alerte->fill([
                    'mois_manques' => $t['mois_manques'],
                    'serie_precedente' => $t['serie_precedente'],
                    'niveau' => $t['niveau'],
                    'motif' => $t['motif'],
                ]);

                if (! $existait) {
                    $alerte->statut = Alerte::A_TRAITER;
                } elseif ($alerte->statut === Alerte::REGULARISEE) {
                    // Les données montrent à nouveau la défaillance : on rouvre l'alerte.
                    $alerte->statut = Alerte::A_TRAITER;
                    $alerte->traite_par = null;
                    $alerte->traite_le = null;
                }

                $alerte->save();
                $existait ? $stats['mis_a_jour']++ : $stats['crees']++;

                $actuelles[$t['contribuable_id'] . '|' . $t['type_impot'] . '|' . $t['periode']] = true;
            }

            // Alertes encore "à traiter" dont le contribuable a depuis déposé → régularisées automatiquement.
            $ouvertes = Alerte::query()
                ->where('regle', Alerte::REGLE_DEFAILLANCE)
                ->where('statut', Alerte::A_TRAITER)
                ->where('periode', '<=', $periode)
                ->when($centreId !== null, fn ($q) => $q->whereHas(
                    'contribuable',
                    fn ($c) => $c->where('centre_id', $centreId)
                ))
                ->get(['id', 'contribuable_id', 'type_impot', 'periode']);

            foreach ($ouvertes as $a) {
                if (! isset($actuelles[$a->contribuable_id . '|' . $a->type_impot . '|' . $a->periode])) {
                    $a->update(['statut' => Alerte::REGULARISEE]);
                    $stats['regularisees']++;
                }
            }
        });

        Cache::forever(self::CACHE_DERNIERE_ANALYSE, ['at' => now()->toIso8601String(), 'periode' => $periode]);

        return $stats;
    }

    /**
     * @param array<int, int> $periodes  périodes déclarées (indices de mois) pour ce contribuable et cet impôt
     * @param array<string, mixed> $cfg
     * @param array<int, array<string, mixed>> $trouvees
     */
    private function evaluer(string $cle, array $periodes, int $ref, array $cfg, array &$trouvees): void
    {
        [$contribuableId, $impot] = explode('|', $cle);
        $deposees = array_flip($periodes);

        // Mois consécutifs manqués jusqu'à la période de référence (la boucle s'arrête forcément :
        // $periodes n'est jamais vide et toutes ses valeurs sont <= $ref).
        $manques = 0;
        while (! isset($deposees[$ref - $manques])) {
            $manques++;
        }

        if ($manques === 0) {
            return; // a déclaré pour la période de référence : pas de défaillance
        }

        $dernier = $ref - $manques; // dernière période déclarée

        $serie = 0;
        while (isset($deposees[$dernier - $serie])) {
            $serie++;
        }

        if ($serie < $cfg['serie_minimale']) {
            return; // pas "habituellement déclarant"
        }

        $debut = $dernier + 1; // première période manquante

        $trouvees[] = [
            'contribuable_id' => (int) $contribuableId,
            'type_impot' => $impot,
            'periode' => self::format($debut),
            'mois_manques' => $manques,
            'serie_precedente' => $serie,
            'niveau' => $this->niveau($manques, $serie, $cfg),
            'motif' => sprintf(
                '%s : %d mois consécutifs déclarés (%s à %s), puis aucune déclaration depuis %s (%d mois manquant%s).',
                $impot,
                $serie,
                self::label($dernier - $serie + 1),
                self::label($dernier),
                self::label($debut),
                $manques,
                $manques > 1 ? 's' : ''
            ),
        ];
    }

    /** @param array<string, mixed> $cfg */
    private function niveau(int $manques, int $serie, array $cfg): string
    {
        $rang = $manques >= $cfg['seuil_eleve'] ? 2 : ($manques >= $cfg['seuil_moyen'] ? 1 : 0);

        if ($serie >= $cfg['serie_longue'] && $rang < 2) {
            $rang++;
        }

        return Alerte::NIVEAUX[$rang];
    }

    /** « 2026-07 » → nombre de mois depuis l'an 0 (pour faire des calculs simples sur les mois). */
    private static function index(string $periode): int
    {
        return ((int) substr($periode, 0, 4)) * 12 + ((int) substr($periode, 5, 2)) - 1;
    }

    private static function format(int $index): string
    {
        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    private static function label(int $index): string
    {
        return self::MOIS[$index % 12] . ' ' . intdiv($index, 12);
    }
}
