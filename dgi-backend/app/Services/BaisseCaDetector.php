<?php

namespace App\Services;

use App\Models\Alerte;
use App\Models\Declaration;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Détection de la BAISSE DU CHIFFRE D'AFFAIRES déclaré.
 *
 *   - TVA (déclaration mensuelle) : le CA du mois est comparé au CA du MÊME MOIS de l'année précédente
 *     (comparer au mois d'avant fausserait l'analyse : saisonnalité) ;
 *   - IR, IS (déclaration annuelle) : le CA de l'exercice est comparé à celui de l'exercice précédent.
 * Si la baisse atteint le seuil (30 % par défaut), une alerte est créée pour cette période.
 *
 *   Exemple : TVA août 2026 : CA de 6 500 000 Ar ; août 2025 : 10 000 000 Ar → baisse de 35 % → alerte « faible ».
 *
 * Le CA vient de la déclaration (ligne 15 de l'IR, ligne 170 de la TVA). Seuils, impôts concernés et fenêtre
 * d'analyse : config/anomalies.php (section « baisse_ca »).
 */
class BaisseCaDetector
{
    private const NOMS_MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * @return array{date: string, analyses: int, crees: int, mis_a_jour: int, regularisees: int}
     */
    public function analyser(DateTimeInterface $date, ?int $centreId = null): array
    {
        $cfg = config('anomalies.baisse_ca');
        $anneeMin = (int) $date->format('Y') - (int) $cfg['fenetre_annees'];

        $query = Declaration::query()
            ->join('contribuables', 'contribuables.id', '=', 'declarations.contribuable_id')
            // Même période l'année précédente (même contribuable, même impôt, même mois)
            ->leftJoin('declarations as precedente', function ($join) {
                $join->on('precedente.contribuable_id', '=', 'declarations.contribuable_id')
                    ->on('precedente.type_impot', '=', 'declarations.type_impot')
                    ->on('precedente.mois', '=', 'declarations.mois')
                    ->whereRaw('precedente.annee_fiscale = declarations.annee_fiscale - 1');
            })
            ->whereIn('declarations.type_impot', $cfg['impots'])
            ->whereNotNull('declarations.chiffre_affaires')
            ->where('declarations.annee_fiscale', '>=', $anneeMin)
            ->select(
                'declarations.contribuable_id',
                'declarations.type_impot',
                'declarations.annee_fiscale',
                'declarations.mois',
                'declarations.chiffre_affaires',
                'declarations.date_limite',
                'precedente.chiffre_affaires as ca_precedent'
            );

        if ($centreId !== null) {
            $query->where('contribuables.centre_id', $centreId);
        }

        $lignes = [];
        foreach ($query->cursor() as $row) {
            $lignes[] = [
                'contribuable_id' => (int) $row->contribuable_id,
                'type_impot' => $row->type_impot,
                'annee_fiscale' => (int) $row->annee_fiscale,
                'mois' => (int) $row->mois,
                'chiffre_affaires' => (float) $row->chiffre_affaires,
                'ca_precedent' => $row->ca_precedent !== null ? (float) $row->ca_precedent : null,
                'date_limite' => $row->date_limite?->toDateString(),
            ];
        }

        $stats = ['date' => $date->format('Y-m-d'), 'analyses' => count($lignes), 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0];
        $trouvees = self::detecter($lignes, $cfg);

        DB::transaction(function () use ($trouvees, $anneeMin, $centreId, &$stats) {
            $actuelles = [];

            foreach ($trouvees as $t) {
                $alerte = Alerte::firstOrNew([
                    'contribuable_id' => $t['contribuable_id'],
                    'regle' => Alerte::REGLE_BAISSE_CA,
                    'type_impot' => $t['type_impot'],
                    'annee_fiscale' => $t['annee_fiscale'],
                    'mois' => $t['mois'],
                ]);

                $existait = $alerte->exists;

                $alerte->fill([
                    'periodes_manquees' => 0,
                    'periodes_declarees' => 0,
                    'date_limite' => $t['date_limite'],
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

                $actuelles[$t['contribuable_id'] . '|' . $t['type_impot'] . '|' . $t['annee_fiscale'] . '|' . $t['mois']] = true;
            }

            // Alertes « à traiter » dont la baisse a disparu (déclaration corrigée) → régularisées
            $ouvertes = Alerte::query()
                ->where('regle', Alerte::REGLE_BAISSE_CA)
                ->where('statut', Alerte::A_TRAITER)
                ->where('annee_fiscale', '>=', $anneeMin)
                ->when($centreId !== null, fn ($q) => $q->whereHas(
                    'contribuable',
                    fn ($c) => $c->where('centre_id', $centreId)
                ))
                ->get(['id', 'contribuable_id', 'type_impot', 'annee_fiscale', 'mois']);

            foreach ($ouvertes as $a) {
                if (! isset($actuelles[$a->contribuable_id . '|' . $a->type_impot . '|' . $a->annee_fiscale . '|' . $a->mois])) {
                    $a->update(['statut' => Alerte::REGULARISEE]);
                    $stats['regularisees']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Cœur de la détection sur des tableaux simples (aucun accès base) : testable seul.
     *
     * @param array<int, array<string, mixed>> $lignes  contribuable_id, type_impot, annee_fiscale, mois, chiffre_affaires,
     *                                                  ca_precedent (même période l'année précédente), date_limite
     * @param array<string, mixed> $cfg
     * @return array<int, array<string, mixed>>
     */
    public static function detecter(array $lignes, array $cfg): array
    {
        $trouvees = [];

        foreach ($lignes as $l) {
            $baisse = self::evaluer($l['chiffre_affaires'], $l['ca_precedent'], $cfg);

            if ($baisse === null) {
                continue;
            }

            $mois = (int) $l['mois'];
            $annee = (int) $l['annee_fiscale'];
            $periode = $mois > 0 ? self::NOMS_MOIS[$mois - 1] . ' ' . $annee : 'l\'exercice ' . $annee;
            $reference = $mois > 0 ? self::NOMS_MOIS[$mois - 1] . ' ' . ($annee - 1) : 'l\'exercice ' . ($annee - 1);

            $trouvees[] = [
                'contribuable_id' => $l['contribuable_id'],
                'type_impot' => $l['type_impot'],
                'annee_fiscale' => $annee,
                'mois' => $mois,
                'date_limite' => $l['date_limite'] ?? null,
                'niveau' => $baisse['niveau'],
                'details' => [
                    'ca_precedent' => $baisse['ca_precedent'],
                    'ca_actuel' => (float) $l['chiffre_affaires'],
                    'baisse_pct' => $baisse['baisse_pct'],
                    'reference' => $mois > 0 ? 'meme_mois_annee_precedente' : 'annee_precedente',
                    'annee_precedente' => $annee - 1,
                ],
                'motif' => sprintf(
                    "%s : chiffre d'affaires de %s Ar déclaré pour %s, en baisse de %s %% par rapport aux %s Ar de %s.",
                    $l['type_impot'],
                    number_format((float) $l['chiffre_affaires'], 0, ',', ' '),
                    $periode,
                    number_format($baisse['baisse_pct'], 1, ',', ' '),
                    number_format($baisse['ca_precedent'], 0, ',', ' '),
                    $reference
                ),
            ];
        }

        return $trouvees;
    }

    /**
     * Compare un CA à celui de la même période l'année précédente. Fonction pure (sans accès base).
     *
     * @param array<string, mixed> $cfg
     * @return array{baisse_pct: float, ca_precedent: float, niveau: string}|null
     */
    public static function evaluer(float $ca, ?float $caPrecedent, array $cfg): ?array
    {
        if ($caPrecedent === null || $caPrecedent <= 0) {
            return null; // aucun CA de comparaison
        }

        $baisse = round(($caPrecedent - $ca) / $caPrecedent * 100, 2);

        if ($baisse < $cfg['seuil_pourcentage']) {
            return null;
        }

        $rang = $baisse >= $cfg['seuil_eleve'] ? 2 : ($baisse >= $cfg['seuil_moyen'] ? 1 : 0);

        return ['baisse_pct' => $baisse, 'ca_precedent' => $caPrecedent, 'niveau' => ['faible', 'moyen', 'eleve'][$rang]];
    }
}
