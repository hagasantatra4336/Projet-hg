<?php

namespace App\Services;

use App\Models\Alerte;
use App\Models\Indicateur;
use App\Models\Regle;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Détection d'une règle PERSONNALISÉE : les conditions de la règle sont évaluées sur chaque déclaration
 * (valeurs lues dans les colonnes de « declarations » désignées par les indicateurs).
 *
 *  - analyser(..., simuler: false) : crée / met à jour les alertes (regle = code de la règle) et régularise celles
 *    dont la condition ne tient plus, comme les règles de l'étape 1 ;
 *  - analyser(..., simuler: true)  : ne touche à AUCUNE alerte ; retourne volumes, répartitions et un échantillon.
 *
 * La même période de l'année précédente (même contribuable, impôt et mois) sert de référence aux mesures « _n1 ».
 */
class DetecteurPersonnalise
{
    public const ECHANTILLON = 200;

    /**
     * @return array<string, mixed>
     */
    public function analyser(Regle $regle, DateTimeInterface $date, ?int $centreId = null, bool $simuler = false): array
    {
        $aujourdhui = $date->format('Y-m-d');

        if (! $simuler && ($regle->statut !== Regle::ACTIVE || ! $regle->actif)) {
            return ['date' => $aujourdhui, 'analyses' => 0, 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0, 'desactivee' => true];
        }

        $conditions = (array) $regle->conditions;
        $impots = array_values((array) $regle->impots);
        $anneeMin = (int) $date->format('Y') - (int) (($regle->parametres['fenetre_annees'] ?? 1));

        $colonnes = $this->colonnes($conditions); // code => colonne (vérifiée)

        $trouvees = [];
        $absentes = 0;
        $analyses = 0;
        $libelles = Indicateur::pluck('libelle', 'code')->all();

        // Parcours en flux : chaque déclaration est évaluée puis oubliée. Avant, toutes les lignes
        // étaient empilées dans un tableau (saturation mémoire possible sur une grosse base) ;
        // seules les déclarations en défaut sont conservées pour créer/mettre à jour les alertes.
        $this->parcourir($colonnes, $impots, $anneeMin, $centreId, function (array $l) use (&$trouvees, &$absentes, &$analyses, $conditions, $regle, $libelles): void {
            $analyses++;

            if (in_array(null, $l['valeurs'], true)) {
                $absentes++;
            }

            $r = EvaluateurConditions::evaluer($conditions, $l);
            if (! $r['ok']) {
                return;
            }

            $trouvees[] = [
                'contribuable_id' => $l['contribuable_id'],
                'type_impot' => $l['type_impot'],
                'annee_fiscale' => $l['annee_fiscale'],
                'mois' => $l['mois'],
                'date_limite' => $l['date_limite'],
                'niveau' => (string) $regle->niveau,
                'motif' => self::motif($regle, $l, $r['constats'], $libelles),
                'details' => [
                    'regle_personnalisee' => $regle->code,
                    'constats' => $r['constats'],
                    'valeurs' => $l['valeurs'],
                    'precedentes' => $l['precedentes'],
                ],
                'centre_id' => $l['centre_id'],
            ];
        });

        if ($simuler) {
            return $this->resume($regle, $aujourdhui, $analyses, $absentes, $trouvees);
        }

        $stats = ['date' => $aujourdhui, 'analyses' => $analyses, 'crees' => 0, 'mis_a_jour' => 0, 'regularisees' => 0];

        DB::transaction(function () use ($trouvees, $regle, $anneeMin, $impots, $centreId, &$stats) {
            $actuelles = [];

            foreach ($trouvees as $t) {
                $alerte = Alerte::firstOrNew([
                    'contribuable_id' => $t['contribuable_id'],
                    'regle' => $regle->code,
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
                    $alerte->statut = Alerte::A_TRAITER;
                    $alerte->traite_par = null;
                    $alerte->traite_le = null;
                    $alerte->forceFill(['pris_par' => null, 'pris_le' => null]);
                }

                $alerte->save();
                $existait ? $stats['mis_a_jour']++ : $stats['crees']++;

                $actuelles[$t['contribuable_id'] . '|' . $t['type_impot'] . '|' . $t['annee_fiscale'] . '|' . $t['mois']] = true;
            }

            $ouvertes = Alerte::query()
                ->where('regle', $regle->code)
                ->where('statut', Alerte::A_TRAITER)
                ->where('annee_fiscale', '>=', $anneeMin)
                ->whereIn('type_impot', $impots)
                ->when($centreId !== null, fn ($q) => $q->whereHas('contribuable', fn ($c) => $c->where('centre_id', $centreId)))
                ->get(['id', 'contribuable_id', 'type_impot', 'annee_fiscale', 'mois']);

            foreach ($ouvertes as $a) {
                if (! isset($actuelles[$a->contribuable_id . '|' . $a->type_impot . '|' . $a->annee_fiscale . '|' . $a->mois])) {
                    $a->forceFill(['statut' => Alerte::REGULARISEE, 'pris_par' => null, 'pris_le' => null])->save();
                    $stats['regularisees']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Colonnes de « declarations » à lire : code d'indicateur => colonne. Refuse tout indicateur inconnu, désactivé
     * ou dont la colonne n'existe pas (le nom de colonne n'est jamais pris tel quel d'une saisie).
     *
     * @return array<string, string>
     */
    private function colonnes(array $conditions): array
    {
        $colonnes = [];
        $connus = Indicateur::where('actif', true)->pluck('colonne', 'code')->all();

        foreach (EvaluateurConditions::indicateursUtilises($conditions) as $code) {
            $col = $connus[$code] ?? null;
            if ($col === null) {
                throw new RuntimeException("Indicateur « {$code} » inconnu ou désactivé.");
            }
            if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $col) || ! Schema::hasColumn('declarations', $col)) {
                throw new RuntimeException("La colonne « {$col} » (indicateur « {$code} ») n'existe pas dans la table des déclarations.");
            }
            $colonnes[$code] = $col;
        }

        return $colonnes;
    }

    /**
     * Évalue chaque déclaration sans les stocker : le curseur ne garde qu'une ligne à la fois,
     * la mémoire reste donc stable quel que soit le volume de la table.
     *
     * @param array<string, string> $colonnes
     * @param array<int, string> $impots
     * @param callable(array<string, mixed>): void $surLigne
     */
    private function parcourir(array $colonnes, array $impots, int $anneeMin, ?int $centreId, callable $surLigne): void
    {
        $q = DB::table('declarations')
            ->join('contribuables', 'contribuables.id', '=', 'declarations.contribuable_id')
            ->leftJoin('declarations as precedente', function ($join) {
                $join->on('precedente.contribuable_id', '=', 'declarations.contribuable_id')
                    ->on('precedente.type_impot', '=', 'declarations.type_impot')
                    ->on('precedente.mois', '=', 'declarations.mois')
                    ->whereRaw('precedente.annee_fiscale = declarations.annee_fiscale - 1');
            })
            ->whereIn('declarations.type_impot', $impots)
            ->where('declarations.annee_fiscale', '>=', $anneeMin)
            ->select(
                'declarations.contribuable_id',
                'declarations.type_impot',
                'declarations.annee_fiscale',
                'declarations.mois',
                'declarations.date_limite',
                'contribuables.centre_id'
            );

        $i = 0;
        $alias = [];
        foreach ($colonnes as $code => $col) {
            $q->addSelect("declarations.{$col} as v_{$i}", "precedente.{$col} as p_{$i}");
            $alias[$code] = $i++;
        }

        if ($centreId !== null) {
            $q->where('contribuables.centre_id', $centreId);
        }

        foreach ($q->cursor() as $r) {
            $valeurs = [];
            $precedentes = [];
            foreach ($alias as $code => $n) {
                $valeurs[$code] = $r->{"v_{$n}"} !== null ? (float) $r->{"v_{$n}"} : null;
                $precedentes[$code] = $r->{"p_{$n}"} !== null ? (float) $r->{"p_{$n}"} : null;
            }
            $surLigne([
                'contribuable_id' => (int) $r->contribuable_id,
                'type_impot' => $r->type_impot,
                'annee_fiscale' => (int) $r->annee_fiscale,
                'mois' => (int) $r->mois,
                'date_limite' => $r->date_limite !== null ? substr((string) $r->date_limite, 0, 10) : null,
                'centre_id' => $r->centre_id !== null ? (int) $r->centre_id : null,
                'valeurs' => $valeurs,
                'precedentes' => $precedentes,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $constats @param array<string, string> $libelles */
    public static function motif(Regle $regle, array $ligne, array $constats, array $libelles): string
    {
        $noms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $periode = $ligne['mois'] > 0 ? $noms[$ligne['mois'] - 1] . ' ' . $ligne['annee_fiscale'] : 'exercice ' . $ligne['annee_fiscale'];

        $phrases = array_map(function (array $c) use ($libelles): string {
            $nom = $libelles[$c['indicateur']] ?? $c['indicateur'];
            $obs = $c['observe'] === null ? 'non renseigné' : number_format((float) $c['observe'], 2, ',', ' ');

            return match ($c['mesure']) {
                'baisse_pct_n1' => "{$nom} en baisse de {$obs} % sur un an",
                'hausse_pct_n1' => "{$nom} en hausse de {$obs} % sur un an",
                'ecart_n1' => "{$nom} : écart de {$obs} sur un an",
                'ratio_pct' => $nom . ' / ' . ($libelles[$c['indicateur_b']] ?? $c['indicateur_b']) . " = {$obs} %",
                default => $c['observe'] === null ? "{$nom} non renseigné" : "{$nom} = {$obs}",
            };
        }, $constats);

        return sprintf('%s, %s : %s (règle « %s »).', $ligne['type_impot'], $periode, implode(' ; ', $phrases), $regle->nom);
    }

    /** @param array<int, array<string, mixed>> $trouvees @return array<string, mixed> */
    private function resume(Regle $regle, string $date, int $analyses, int $absentes, array $trouvees): array
    {
        $niveaux = [];
        $impots = [];
        $centres = [];
        foreach ($trouvees as $t) {
            $niveaux[$t['niveau']] = ($niveaux[$t['niveau']] ?? 0) + 1;
            $impots[$t['type_impot']] = ($impots[$t['type_impot']] ?? 0) + 1;
            $c = $t['centre_id'] ?? 0;
            $centres[$c] = ($centres[$c] ?? 0) + 1;
        }

        return [
            'date' => $date,
            'analyses' => $analyses,
            'alertes' => count($trouvees),
            'valeurs_absentes' => $absentes, // déclarations où au moins une valeur utilisée est vide
            'par_niveau' => $niveaux,
            'par_impot' => $impots,
            'par_centre' => $centres,        // centre_id (0 = sans centre) => nombre
            'alertes_existantes' => Alerte::where('regle', $regle->code)->count(),
            'echantillon' => array_map(fn ($t) => [
                'contribuable_id' => $t['contribuable_id'],
                'type_impot' => $t['type_impot'],
                'annee_fiscale' => $t['annee_fiscale'],
                'mois' => $t['mois'],
                'motif' => $t['motif'],
            ], array_slice($trouvees, 0, self::ECHANTILLON)),
        ];
    }
}
