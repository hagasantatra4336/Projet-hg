<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Récupère les déclarations depuis une base PostgreSQL SOURCE (connexion « source », voir config/dgi.php)
 * et les recopie dans les tables de l'application (contribuables + declarations), prêtes pour l'analyse
 * (DefaillanceDetector). Mêmes règles que l'import CSV (EXF-001) :
 *   - type_impot ∈ config('anomalies.defaillance.impots') ; periode « AAAA-MM » ; montant >= 0 ;
 *   - le centre doit exister (comparaison sans tenir compte de la casse) ;
 *   - une déclaration déjà présente (même contribuable + impôt + période) est mise à jour (pas de doublon).
 *
 * La base source n'est JAMAIS modifiée : uniquement des SELECT.
 */
class SourceDeclarationImporter
{
    public const PERIODE_REGEX = '/^\d{4}-(0[1-9]|1[0-2])$/';

    private const MAX_ERRORS = 15;
    private const LOT = 500;

    public function connexion(): ConnectionInterface
    {
        return DB::connection((string) config('dgi.source.connection'));
    }

    /**
     * Vérifie que la base source répond et que la table attendue est lisible.
     *
     * @return array{base: string, version: string, table: string, lignes: int}
     */
    public function tester(): array
    {
        $cx = $this->connexion();
        $table = (string) config('dgi.source.table');

        return [
            'base' => (string) $cx->getDatabaseName(),
            'version' => $cx->getDriverName() === 'pgsql' ? (string) ($cx->selectOne('select version() as v')->v ?? '') : '',
            'table' => $table,
            'lignes' => (int) $cx->table($table)->count(),
        ];
    }

    /**
     * @param string|null $depuis    première période à lire « AAAA-MM » (incluse), sinon tout l'historique
     * @param string|null $jusqua    dernière période à lire « AAAA-MM » (incluse), sinon jusqu'à la fin
     * @param int|null    $centreId  limiter à un centre (admin) ; null = tous les centres
     *
     * @return array{lues: int, importees: int, rejetees: int, contribuables_crees: int, erreurs: array<int, string>}
     */
    public function importer(?string $depuis = null, ?string $jusqua = null, ?int $centreId = null): array
    {
        foreach (['depuis' => $depuis, 'jusqua' => $jusqua] as $nom => $valeur) {
            if ($valeur !== null && ! preg_match(self::PERIODE_REGEX, $valeur)) {
                throw new InvalidArgumentException("Période « {$nom} » invalide : utilisez le format AAAA-MM.");
            }
        }

        $col = (array) config('dgi.source.colonnes');
        $impots = (array) config('anomalies.defaillance.impots');

        $query = $this->connexion()->table((string) config('dgi.source.table'));

        // « colonne as champ » : les lignes lues portent toujours les mêmes noms de champs, quel que soit le schéma source.
        $query->select(array_map(fn (string $champ) => $col[$champ] . ' as ' . $champ, [
            'nif', 'nom', 'centre', 'type_impot', 'periode', 'montant', 'date_depot',
        ]));

        if ($depuis !== null) {
            $query->where($col['periode'], '>=', $depuis);
        }
        if ($jusqua !== null) {
            $query->where($col['periode'], '<=', $jusqua);
        }
        if ($centreId !== null) {
            $centre = Centre::find($centreId);
            // Aucun centre correspondant : on ne lit rien plutôt que de tout lire.
            $query->whereRaw('lower(' . $query->getGrammar()->wrap($col['centre']) . ') = lower(?)', [$centre?->nom ?? '']);
        }

        $query->orderBy($col['periode'])->orderBy($col['nif'])->orderBy($col['type_impot']);

        $centres = [];
        foreach (Centre::pluck('id', 'nom') as $nom => $id) {
            $centres[mb_strtolower((string) $nom)] = (int) $id;
        }

        $stats = ['lues' => 0, 'importees' => 0, 'rejetees' => 0, 'contribuables_crees' => 0, 'erreurs' => []];

        DB::transaction(function () use ($query, $impots, $centres, &$stats) {
            $ids = [];  // NIF => id du contribuable
            $lot = [];  // clé unique => ligne à écrire (dédoublonne)
            $now = now()->toDateTimeString();

            foreach ($query->cursor() as $row) {
                $stats['lues']++;

                [$ligne, $raison] = self::normaliser($row, $impots);

                if ($ligne === null) {
                    $this->rejeter($stats, $row, $raison);
                    continue;
                }

                $centreId = $centres[mb_strtolower($ligne['centre'])] ?? null;
                if ($centreId === null) {
                    $this->rejeter($stats, $row, 'centre « ' . $ligne['centre'] . ' » introuvable.');
                    continue;
                }

                $nif = $ligne['nif'];
                if (! isset($ids[$nif])) {
                    $existant = Contribuable::where('nif', $nif)->first();

                    if ($existant) {
                        $ids[$nif] = $existant->id;
                    } else {
                        $ids[$nif] = Contribuable::create(['nif' => $nif, 'nom' => $ligne['nom'], 'centre_id' => $centreId])->id;
                        $stats['contribuables_crees']++;
                    }
                }

                $lot[$ids[$nif] . '|' . $ligne['type_impot'] . '|' . $ligne['periode']] = [
                    'contribuable_id' => $ids[$nif],
                    'type_impot' => $ligne['type_impot'],
                    'periode' => $ligne['periode'],
                    'montant' => $ligne['montant'],
                    'date_depot' => $ligne['date_depot'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $stats['importees']++;

                if (count($lot) >= self::LOT) {
                    $this->ecrire($lot);
                    $lot = [];
                }
            }

            $this->ecrire($lot);
        });

        return $stats;
    }

    /**
     * Valide et normalise une ligne lue dans la base source (fonction pure, sans accès base).
     *
     * @param object            $row    champs : nif, nom, centre, type_impot, periode, montant, date_depot
     * @param array<int,string> $impots impôts autorisés
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}  [ligne normalisée, null] ou [null, raison du rejet]
     */
    public static function normaliser(object $row, array $impots): array
    {
        $nif = trim((string) ($row->nif ?? ''));
        $nom = trim((string) ($row->nom ?? ''));
        $centre = trim((string) ($row->centre ?? ''));
        $impot = strtoupper(trim((string) ($row->type_impot ?? '')));
        $periode = trim((string) ($row->periode ?? ''));
        $montant = str_replace([' ', "\xC2\xA0"], '', trim((string) ($row->montant ?? '')));
        $montant = str_replace(',', '.', $montant);

        if ($nif === '' || strlen($nif) > 30) {
            return [null, 'NIF vide ou trop long.'];
        }
        if ($nom === '') {
            return [null, 'nom vide.'];
        }
        if (! in_array($impot, $impots, true)) {
            return [null, 'type_impot « ' . $impot . ' » inconnu (attendu : ' . implode(', ', $impots) . ').'];
        }
        if (! preg_match(self::PERIODE_REGEX, $periode)) {
            return [null, 'periode « ' . $periode . ' » invalide (format AAAA-MM).'];
        }
        if ($montant === '' || ! is_numeric($montant) || (float) $montant < 0) {
            return [null, 'montant « ' . trim((string) ($row->montant ?? '')) . ' » invalide.'];
        }
        if ($centre === '') {
            return [null, 'centre vide.'];
        }

        // date_depot facultative ; PostgreSQL renvoie « AAAA-MM-JJ » (ou « AAAA-MM-JJ HH:MM:SS » pour un timestamp).
        $dateDepot = null;
        $brute = trim((string) ($row->date_depot ?? ''));
        if ($brute !== '') {
            $jour = substr($brute, 0, 10);
            $d = \DateTime::createFromFormat('!Y-m-d', $jour);

            if ($d === false || $d->format('Y-m-d') !== $jour) {
                return [null, 'date_depot « ' . $brute . ' » invalide.'];
            }
            $dateDepot = $jour;
        }

        return [[
            'nif' => $nif,
            'nom' => $nom,
            'centre' => $centre,
            'type_impot' => $impot,
            'periode' => $periode,
            'montant' => $montant,
            'date_depot' => $dateDepot,
        ], null];
    }

    /** @param array<string, mixed> $stats */
    private function rejeter(array &$stats, object $row, ?string $raison): void
    {
        $stats['rejetees']++;

        if (count($stats['erreurs']) < self::MAX_ERRORS) {
            $stats['erreurs'][] = sprintf('NIF « %s », période « %s » : %s', trim((string) ($row->nif ?? '')), trim((string) ($row->periode ?? '')), $raison);
        }
    }

    /** @param array<string, array<string, mixed>> $lot */
    private function ecrire(array $lot): void
    {
        if ($lot !== []) {
            Declaration::upsert(array_values($lot), ['contribuable_id', 'type_impot', 'periode'], ['montant', 'date_depot', 'updated_at']);
        }
    }
}
