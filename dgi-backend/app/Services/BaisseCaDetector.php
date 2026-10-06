<?php

namespace App\Services;

use App\Models\Alerte;
use App\Models\Declaration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Détection de la BAISSE DU CHIFFRE D'AFFAIRES déclaré.
 *
 * Pour chaque contribuable et chaque impôt, on compare le chiffre d'affaires de chaque déclaration récente
 * à celui de la déclaration PRÉCÉDENTE du même impôt (la dernière déclarée avant elle, même si ce n'est pas le
 * mois d'avant). Si la baisse atteint le seuil (30 % par défaut), une alerte est créée sur la période concernée.
 *
 *   Exemple : CA de 10 000 000 Ar en juin, 6 500 000 Ar en juillet → baisse de 35 % → alerte « faible ».
 *
 * Seuils, impôts concernés et fenêtre d'analyse : config/anomalies.php (section « baisse_ca »).
 * Les déclarations sans chiffre d'affaires renseigné sont ignorées.
 */
class BaisseCaDetector
{
    private const MOIS = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * @return array{periode: string, analyses: int, crees: int, mis_a_jour: int, regularisees: int}
     */
    public function analyser(string $periode, ?int $centreId = null): array
    {
        $cfg = config('anomalies.baisse_ca');
        $fenetre = max(1, (int) $cfg['fenetre_mois']);
        $debut = Carbon::createFromFormat('!Y-m', $periode)->subMonthsNoOverflow($fenetre - 1)->format('Y-m');

        $query = Declaration::query()
            ->join('contribuables', 'contribuables.id', '=', 'declarations.contribuable_id')
            ->whereIn('declarations.type_impot', $cfg['impots'])
            ->whereNotNull('declarations.chiffre_affaires')
            ->where('declarations.periode', '<=', $periode)
            // On ne relit que les contribuables qui ont une déclaration dans la fenêtre analysée
            ->whereIn('declarations.contribuable_id', function ($q) use ($debut, $periode) {
                $q->select('contribuable_id')
                    ->from('declarations')
                    ->whereBetween('periode', [$debut, $periode])
                    ->whereNotNull('chiffre_affaires');
            })
            ->orderBy('declarations.contribuable_id')
            ->orderBy('declarations.type_impot')
            ->orderBy('declarations.periode')
            ->select('declarations.contribuable_id', 'declarations.type_impot', 'declarations.periode', 'declarations.chiffre_affaires');

        if ($centreId !== null) {
            $query->where('contribuables.centre_id', $centreId);
        }

        $stats = ['periode' => $periode, 'analyses' => 0, 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0];

        // 1) Lecture seule : repérer les baisses
        $trouvees = [];
        $courant = null;
        $prec = null; // déclaration précédente du même contribuable / impôt

        foreach ($query->cursor() as $row) {
            $cle = $row->contribuable_id . '|' . $row->type_impot;

            if ($cle !== $courant) {
                $courant = $cle;
                $prec = null;
            }

            $ca = (float) $row->chiffre_affaires;

            if ($row->periode >= $debut) { // "AAAA-MM" se compare comme du texte
                $stats['analyses']++;

                if ($prec !== null && $prec['ca'] > 0) {
                    $baisse = round(($prec['ca'] - $ca) / $prec['ca'] * 100, 2);

                    if ($baisse >= $cfg['seuil_pourcentage']) {
                        $trouvees[] = [
                            'contribuable_id' => (int) $row->contribuable_id,
                            'type_impot' => $row->type_impot,
                            'periode' => $row->periode,
                            'niveau' => $this->niveau($baisse, $cfg),
                            'details' => [
                                'ca_precedent' => $prec['ca'],
                                'ca_actuel' => $ca,
                                'baisse_pct' => $baisse,
                                'periode_precedente' => $prec['periode'],
                            ],
                            'motif' => sprintf(
                                "%s : chiffre d'affaires déclaré de %s Ar en %s, en baisse de %s %% par rapport aux %s Ar déclarés en %s.",
                                $row->type_impot,
                                $this->nombre($ca),
                                $this->label($row->periode),
                                number_format($baisse, 1, ',', ' '),
                                $this->nombre($prec['ca']),
                                $this->label($prec['periode'])
                            ),
                        ];
                    }
                }
            }

            $prec = ['periode' => $row->periode, 'ca' => $ca];
        }

        // 2) Écriture : créer / mettre à jour les alertes, puis lever celles qui ne tiennent plus
        DB::transaction(function () use ($trouvees, $periode, $debut, $centreId, &$stats) {
            $actuelles = [];

            foreach ($trouvees as $t) {
                $alerte = Alerte::firstOrNew([
                    'contribuable_id' => $t['contribuable_id'],
                    'regle' => Alerte::REGLE_BAISSE_CA,
                    'type_impot' => $t['type_impot'],
                    'periode' => $t['periode'],
                ]);

                $existait = $alerte->exists;

                $alerte->fill([
                    'mois_manques' => 0,
                    'serie_precedente' => 0,
                    'niveau' => $t['niveau'],
                    'motif' => $t['motif'],
                    'details' => $t['details'],
                ]);

                if (! $existait) {
                    $alerte->statut = Alerte::A_TRAITER;
                } elseif ($alerte->statut === Alerte::REGULARISEE) {
                    // La baisse est de nouveau constatée dans les données : on rouvre l'alerte.
                    $alerte->statut = Alerte::A_TRAITER;
                    $alerte->traite_par = null;
                    $alerte->traite_le = null;
                }

                $alerte->save();
                $existait ? $stats['mis_a_jour']++ : $stats['crees']++;

                $actuelles[$t['contribuable_id'] . '|' . $t['type_impot'] . '|' . $t['periode']] = true;
            }

            // Alertes « à traiter » dont la baisse a disparu (déclaration corrigée) → régularisées
            $ouvertes = Alerte::query()
                ->where('regle', Alerte::REGLE_BAISSE_CA)
                ->where('statut', Alerte::A_TRAITER)
                ->whereBetween('periode', [$debut, $periode])
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

        return $stats;
    }

    /** @param array<string, mixed> $cfg */
    private function niveau(float $baisse, array $cfg): string
    {
        $rang = $baisse >= $cfg['seuil_eleve'] ? 2 : ($baisse >= $cfg['seuil_moyen'] ? 1 : 0);

        return Alerte::NIVEAUX[$rang];
    }

    private function nombre(float $valeur): string
    {
        return number_format($valeur, 0, ',', ' ');
    }

    /** « 2026-07 » → « juillet 2026 » */
    private function label(string $periode): string
    {
        return self::MOIS[(int) substr($periode, 5, 2) - 1] . ' ' . substr($periode, 0, 4);
    }
}
