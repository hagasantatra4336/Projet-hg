<?php

namespace App\Services;

use App\Models\BaseRisque;
use App\Models\Contribuable;
use App\Support\Nif;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Reconstitue la « base d'analyse risque » (table base_risque) depuis la base source (lecture seule, SELECT uniquement) :
 *   - IR et TVA : valeurs de lignes précises des déclarations (config/risque.php, « lignes »), dernière version seulement ;
 *   - recoupement : récapitulatif par NIF et année ;
 *   - DAU : TVA des DAU modèle IM4 et valeur CAF des DAU modèle EX1, par NIF et année.
 * Les contribuables doivent déjà exister (import de la source habituel). Un NIF inconnu est ignoré et compté.
 * Une ligne déjà présente est mise à jour, jamais dupliquée.
 */
class ImportBaseRisque
{
    private const LOT = 500;

    private ConnectionInterface $cx;

    /** @return array<string, mixed> */
    public function importer(?string $depuis = null, ?string $jusqua = null): array
    {
        foreach (['depuis' => $depuis, 'jusqua' => $jusqua] as $nom => $v) {
            if ($v !== null && ! preg_match('/^\d{4}$/', $v)) {
                throw new InvalidArgumentException("Année « {$nom} » invalide : utilisez 4 chiffres (ex. 2025).");
            }
        }

        $this->cx = DB::connection((string) config('dgi.source.connection'));
        $ids = Contribuable::query()->pluck('id', 'nif')->all();

        $stats = ['ecrites' => 0, 'nif_inconnus' => 0, 'nif_exemples' => [], 'tables_absentes' => []];

        foreach ([BaseRisque::IR, BaseRisque::TVA] as $impot) {
            $this->importerLignes($impot, $ids, $depuis, $jusqua, $stats);
        }
        $this->importerRecoupement($ids, $depuis, $jusqua, $stats);
        $this->importerDau($ids, $depuis, $jusqua, $stats);

        return $stats;
    }

    // ------------------------------------------------------------------ IR et TVA

    /** @param array<string, int> $ids */
    private function importerLignes(string $impot, array $ids, ?string $depuis, ?string $jusqua, array &$stats): void
    {
        $def = (array) config("dgi.source.declarations.{$impot}");
        $table = (string) ($def['table'] ?? '');
        if (! $this->tableExiste($table)) {
            $stats['tables_absentes'][] = $table;

            return;
        }

        $col = $this->colonnes($table, (array) $def['colonnes'], ['nif', 'annee', 'ligne', 'montant']);
        $w = fn (string $c) => $this->cx->getQueryGrammar()->wrap($c);

        $lignesVoulues = (array) config("risque.lignes.{$impot}");
        $toutes = array_values(array_unique(array_merge(...array_values($lignesVoulues))));

        $mois = $col['mois'] !== null ? 'coalesce(' . $w($col['mois']) . ', 0)' : '0';
        $version = $col['version'] !== null ? 'coalesce(' . $w($col['version']) . ', 0)' : '0';
        $montant = $col['montant_liquide'] !== null
            ? 'coalesce(' . $w($col['montant']) . ', ' . $w($col['montant_liquide']) . ')'
            : $w($col['montant']);

        // Pas de constante dans GROUP BY / ORDER BY (PostgreSQL la lirait comme un numéro de colonne)
        $groupes = array_filter([
            $w($col['nif']), $w($col['annee']),
            $col['mois'] !== null ? $mois : null,
            $col['version'] !== null ? $version : null,
            $w($col['ligne']),
        ]);

        $query = $this->cx->table($table)
            ->selectRaw(
                $w($col['nif']) . ' as nif, ' . $w($col['annee']) . ' as annee, ' . $mois . ' as mois, ' . $version . ' as version, '
                . $w($col['ligne']) . ' as ligne, sum(' . $montant . ') as montant'
            )
            ->whereIn($col['ligne'], $toutes)
            ->groupByRaw(implode(', ', $groupes))
            ->orderByRaw(implode(', ', array_filter([$w($col['nif']), $w($col['annee']), $col['mois'] !== null ? $mois : null])));

        if ($depuis !== null) {
            $query->where($col['annee'], '>=', $depuis);
        }
        if ($jusqua !== null) {
            $query->where($col['annee'], '<=', $jusqua);
        }

        $lot = [];
        $courant = null;              // NIF en cours (les lignes arrivent triées par NIF)
        $tampon = [];                 // "annee|mois" => version => ligne => montant

        $vider = function () use (&$tampon, &$courant, &$lot, &$stats, $impot, $ids, $lignesVoulues) {
            if ($courant !== null) {
                $this->versLot($impot, $courant, $tampon, $ids, $lignesVoulues, $lot, $stats);
            }
            $tampon = [];
        };

        foreach ($query->cursor() as $row) {
            $nif = Nif::normaliser($row->nif);
            if ($nif !== $courant) {
                $vider();
                $courant = $nif;
            }
            $tampon[((int) $row->annee) . '|' . ((int) $row->mois)][(int) $row->version][(int) $row->ligne] = (float) $row->montant;

            if (count($lot) >= self::LOT) {
                $this->ecrire($impot, $lot, $stats);
                $lot = [];
            }
        }
        $vider();
        $this->ecrire($impot, $lot, $stats);
    }

    /**
     * Transforme les lignes d'un NIF en lignes de base (dernière version de chaque déclaration).
     *
     * @param array<string, array<int, array<int, float>>> $tampon
     * @param array<string, int> $ids
     * @param array<string, array<int, int>> $lignesVoulues indicateur => numéros de lignes
     * @param array<int, array<string, mixed>> $lot
     */
    private function versLot(string $impot, string $nif, array $tampon, array $ids, array $lignesVoulues, array &$lot, array &$stats): void
    {
        if (! isset($ids[$nif])) {
            self::inconnu($stats, $nif);

            return;
        }

        foreach ($tampon as $cle => $versions) {
            [$annee, $mois] = array_map('intval', explode('|', $cle));
            krsort($versions);
            $lignes = reset($versions); // version la plus récente

            $valeurs = [];
            foreach ($lignesVoulues as $indicateur => $numeros) {
                $somme = null;
                foreach ($numeros as $n) {
                    if (isset($lignes[$n])) {
                        $somme = ($somme ?? 0.0) + $lignes[$n];
                    }
                }
                $valeurs[$indicateur] = $somme;
            }

            $lot[] = $this->ligneBase($ids[$nif], $impot, $annee, $mois, $valeurs);
        }
    }

    // ------------------------------------------------------------------ Recoupement

    /** @param array<string, int> $ids */
    private function importerRecoupement(array $ids, ?string $depuis, ?string $jusqua, array &$stats): void
    {
        $cfg = (array) config('risque.recoupement');
        $table = (string) $cfg['table'];
        if (! $this->tableExiste($table)) {
            $stats['tables_absentes'][] = $table;

            return;
        }

        $col = $this->colonnes($table, (array) $cfg['colonnes'], ['nif', 'annee']);
        $w = fn (string $c) => $this->cx->getQueryGrammar()->wrap($c);

        $select = [$w($col['nif']) . ' as nif', $w($col['annee']) . ' as annee'];
        $indicateurs = [];
        foreach ($col as $champ => $nom) {
            if ($nom !== null && ! in_array($champ, ['nif', 'annee'], true)) {
                $select[] = 'sum(' . $w($nom) . ') as ' . $champ;
                $indicateurs[] = $champ;
            }
        }

        $query = $this->cx->table($table)->selectRaw(implode(', ', $select))
            ->groupByRaw($w($col['nif']) . ', ' . $w($col['annee']));
        if ($depuis !== null) {
            $query->where($col['annee'], '>=', $depuis);
        }
        if ($jusqua !== null) {
            $query->where($col['annee'], '<=', $jusqua);
        }

        $lot = [];
        foreach ($query->cursor() as $row) {
            $nif = Nif::normaliser($row->nif);
            if (! isset($ids[$nif])) {
                self::inconnu($stats, $nif);
                continue;
            }
            $valeurs = [];
            foreach ($indicateurs as $champ) {
                $valeurs[$champ] = $row->{$champ} !== null ? (float) $row->{$champ} : null;
            }
            $lot[] = $this->ligneBase($ids[$nif], BaseRisque::RECOUPEMENT, (int) $row->annee, 0, $valeurs);

            if (count($lot) >= self::LOT) {
                $this->ecrire(BaseRisque::RECOUPEMENT, $lot, $stats);
                $lot = [];
            }
        }
        $this->ecrire(BaseRisque::RECOUPEMENT, $lot, $stats);
    }

    // ------------------------------------------------------------------ DAU

    /** @param array<string, int> $ids */
    private function importerDau(array $ids, ?string $depuis, ?string $jusqua, array &$stats): void
    {
        $cfg = (array) config('risque.dau');
        $table = (string) $cfg['table'];
        if (! $this->tableExiste($table)) {
            $stats['tables_absentes'][] = $table;

            return;
        }

        $col = $this->colonnes($table, (array) $cfg['colonnes'], ['nif', 'annee', 'modele', 'tva', 'valeur_caf']);
        $w = fn (string $c) => $this->cx->getQueryGrammar()->wrap($c);
        $modele = 'upper(trim(' . $w($col['modele']) . '))';

        $query = $this->cx->table($table)
            ->selectRaw(
                $w($col['nif']) . ' as nif, ' . $w($col['annee']) . ' as annee, '
                . "sum(case when {$modele} = ? then " . $w($col['tva']) . ' end) as dau_tva_im4, '
                . "sum(case when {$modele} = ? then " . $w($col['valeur_caf']) . ' end) as dau_caf_ex1',
                [strtoupper((string) $cfg['modele_import']), strtoupper((string) $cfg['modele_export'])]
            )
            ->groupByRaw($w($col['nif']) . ', ' . $w($col['annee']));
        if ($depuis !== null) {
            $query->where($col['annee'], '>=', $depuis);
        }
        if ($jusqua !== null) {
            $query->where($col['annee'], '<=', $jusqua);
        }

        $lot = [];
        foreach ($query->cursor() as $row) {
            $nif = Nif::normaliser($row->nif);
            if (! isset($ids[$nif])) {
                self::inconnu($stats, $nif);
                continue;
            }
            $lot[] = $this->ligneBase($ids[$nif], BaseRisque::DAU, (int) $row->annee, 0, [
                'dau_tva_im4' => $row->dau_tva_im4 !== null ? (float) $row->dau_tva_im4 : null,
                'dau_caf_ex1' => $row->dau_caf_ex1 !== null ? (float) $row->dau_caf_ex1 : null,
            ]);

            if (count($lot) >= self::LOT) {
                $this->ecrire(BaseRisque::DAU, $lot, $stats);
                $lot = [];
            }
        }
        $this->ecrire(BaseRisque::DAU, $lot, $stats);
    }

    // ------------------------------------------------------------------ Écriture et accès source

    /**
     * Une ligne complète (toutes les colonnes, null si non concernée) : exigé par l'écriture groupée.
     *
     * @param array<string, float|null> $valeurs
     * @return array<string, mixed>
     */
    private function ligneBase(int $contribuableId, string $source, int $annee, int $mois, array $valeurs): array
    {
        $ligne = ['contribuable_id' => $contribuableId, 'source' => $source, 'annee_fiscale' => $annee, 'mois' => $mois];
        foreach (BaseRisque::toutesColonnes() as $c) {
            $ligne[$c] = $valeurs[$c] ?? null;
        }
        $now = now()->toDateTimeString();
        $ligne['created_at'] = $now;
        $ligne['updated_at'] = $now;

        return $ligne;
    }

    /** @param array<int, array<string, mixed>> $lot */
    private function ecrire(string $source, array $lot, array &$stats): void
    {
        if ($lot === []) {
            return;
        }

        // Une même clé deux fois dans un lot ferait échouer l'écriture : on garde la dernière
        $uniques = [];
        foreach ($lot as $l) {
            $uniques[$l['contribuable_id'] . '|' . $l['source'] . '|' . $l['annee_fiscale'] . '|' . $l['mois']] = $l;
        }

        DB::table('base_risque')->upsert(
            array_values($uniques),
            ['contribuable_id', 'source', 'annee_fiscale', 'mois'],
            array_merge(BaseRisque::COLONNES[$source], ['updated_at']) // seules les colonnes de cette source sont mises à jour
        );
        $stats['ecrites'] += count($uniques);
    }

    /** @param array<string, mixed> $stats */
    private static function inconnu(array &$stats, string $nif): void
    {
        $stats['nif_inconnus']++;
        if (count($stats['nif_exemples']) < 5 && ! in_array($nif, $stats['nif_exemples'], true)) {
            $stats['nif_exemples'][] = $nif;
        }
    }

    private function tableExiste(string $table): bool
    {
        return $table !== '' && Schema::connection((string) config('dgi.source.connection'))->hasTable($table);
    }

    /**
     * Retrouve les vrais noms de colonnes (sans tenir compte de la casse).
     *
     * @param array<string, string|null> $voulues
     * @param array<int, string> $obligatoires
     * @return array<string, string|null>
     */
    private function colonnes(string $table, array $voulues, array $obligatoires): array
    {
        $reelles = [];
        foreach (Schema::connection((string) config('dgi.source.connection'))->getColumnListing($table) as $nom) {
            $reelles[mb_strtolower((string) $nom)] = (string) $nom;
        }

        $resultat = [];
        $manquantes = [];
        foreach ($voulues as $champ => $nom) {
            $resultat[$champ] = ($nom === null || $nom === '') ? null : ($reelles[mb_strtolower((string) $nom)] ?? null);
            if ($resultat[$champ] === null && in_array($champ, $obligatoires, true)) {
                $manquantes[] = (string) $nom;
            }
        }

        if ($manquantes !== []) {
            throw new InvalidArgumentException(
                "Colonnes introuvables dans la table « {$table} » : " . implode(', ', $manquantes)
                . '. Corrigez config/risque.php (ou config/dgi.php pour l\'IR et la TVA) ou le .env.'
            );
        }

        return $resultat;
    }
}
