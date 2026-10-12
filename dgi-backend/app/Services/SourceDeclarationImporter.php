<?php

namespace App\Services;

use App\Models\Assujettissement;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Support\Nif;
use DateTime;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Récupère les données depuis la base PostgreSQL SOURCE (connexion « source », voir config/dgi.php) et les recopie
 * dans les tables de l'application, prêtes pour l'analyse (AnomalieAnalyseur).
 *
 *   1. contribuables   : NIF, nom, centre gestionnaire, état (actif, bloqué, cessation, radié) et dates ;
 *   2. assujettissements : obligations déclaratives (impôt, année, périodicité, actif, exonéré) ;
 *   3. déclarations   : une table source PAR IMPÔT, en « une ligne par ligne du formulaire ». Pour chaque déclaration
 *                       (NIF, année fiscale, mois) on lit le chiffre d'affaires (ligne 15 de l'IR, ligne 170 de la TVA),
 *                       la date limite (DATE_ECHEANCE) et la date de dépôt (DATE_RECEPTION), dernière version seulement.
 *
 * Règles : NIF sur 10 chiffres ; type d'impôt connu ; année fiscale à 4 chiffres ; montants >= 0 ; une déclaration déjà
 * présente (même contribuable + impôt + année + mois) est mise à jour. Un centre introuvable ou ambigu n'empêche pas
 * l'import : le contribuable est créé sans centre (avertissement).
 *
 * La base source n'est JAMAIS modifiée : uniquement des SELECT.
 */
class SourceDeclarationImporter
{
    public const ANNEE_REGEX = '/^\d{4}$/';

    private const MAX_ERRORS = 15;
    private const LOT = 500;

    public function connexion(): ConnectionInterface
    {
        return DB::connection((string) config('dgi.source.connection'));
    }

    /**
     * Vérifie que la base source répond et que les tables attendues sont lisibles.
     *
     * @return array{base: string, version: string, table: string, lignes: int, tables: array<int, array{table: string, existe: bool, lignes: int|null}>}
     */
    public function tester(): array
    {
        $cx = $this->connexion();
        $noms = [];

        foreach ((array) config('dgi.source.declarations') as $def) {
            $noms[] = (string) $def['table'];
        }
        foreach (['assujettissements', 'contribuables', 'centres'] as $cle) {
            $noms[] = (string) config("dgi.source.{$cle}.table");
        }

        $tables = [];
        $total = 0;
        foreach ($noms as $i => $nom) {
            $existe = $this->tableExiste($nom);
            $lignes = $existe ? (int) $cx->table($nom)->count() : null;
            $tables[] = ['table' => $nom, 'existe' => $existe, 'lignes' => $lignes];
            if ($i < count((array) config('dgi.source.declarations'))) {
                $total += (int) $lignes; // lignes des tables de déclarations
            }
        }

        return [
            'base' => (string) $cx->getDatabaseName(),
            'version' => $cx->getDriverName() === 'pgsql' ? (string) ($cx->selectOne('select version() as v')->v ?? '') : '',
            'table' => implode(', ', $noms),
            'lignes' => $total,
            'tables' => $tables,
        ];
    }

    /**
     * Importe les centres gestionnaires officiels (code, libellé, abréviation) dans la table des centres.
     * Un centre existant (même code, ou même nom que le libellé ou l'abréviation) est complété, sinon créé.
     *
     * @return array{lues: int, crees: int, mis_a_jour: int}
     */
    public function importerCentres(): array
    {
        $cfg = (array) config('dgi.source.centres');
        $table = (string) $cfg['table'];

        if (! $this->tableExiste($table)) {
            throw new InvalidArgumentException("Table des centres « {$table} » introuvable dans la base source (DGI_SOURCE_TABLE_CENTRES).");
        }

        $col = $this->colonnes($table, (array) $cfg['colonnes'], ['code', 'libelle']);
        $select = [];
        foreach (['code', 'libelle', 'abreviation'] as $champ) {
            if ($col[$champ] !== null) {
                $select[] = $col[$champ] . ' as ' . $champ;
            }
        }

        $stats = ['lues' => 0, 'crees' => 0, 'mis_a_jour' => 0];

        $parCode = [];
        $parNom = [];
        foreach (Centre::all() as $c) {
            if ($c->code) {
                $parCode[CentreResolver::normaliser($c->code)] = $c;
            }
            $parNom[CentreResolver::normaliser($c->nom)] = $c;
        }

        DB::transaction(function () use ($table, $select, &$stats, &$parCode, &$parNom) {
            foreach ($this->connexion()->table($table)->select($select)->cursor() as $row) {
                $code = trim((string) ($row->code ?? ''));
                $libelle = trim((string) ($row->libelle ?? ''));
                $abrev = trim((string) ($row->abreviation ?? ''));

                if ($code === '' || $libelle === '' || CentreResolver::normaliser($libelle) === 'NON RENSEIGNE') {
                    continue;
                }
                $stats['lues']++;

                $centre = $parCode[CentreResolver::normaliser($code)]
                    ?? $parNom[CentreResolver::normaliser($libelle)]
                    ?? ($abrev !== '' ? ($parNom[CentreResolver::normaliser($abrev)] ?? null) : null);

                if ($centre) {
                    $centre->fill(['code' => $code, 'abreviation' => $abrev !== '' ? $abrev : $centre->abreviation]);

                    if ($centre->isDirty()) {
                        $centre->save();
                        $stats['mis_a_jour']++;
                    }
                } else {
                    $centre = Centre::create(['nom' => $libelle, 'code' => $code, 'abreviation' => $abrev !== '' ? $abrev : null]);
                    $stats['crees']++;
                }

                $parCode[CentreResolver::normaliser($code)] = $centre;
                $parNom[CentreResolver::normaliser($centre->nom)] = $centre;
            }
        });

        return $stats;
    }

    /**
     * @param string|null $depuis    première année fiscale à lire « AAAA » (incluse), sinon tout l'historique
     * @param string|null $jusqua    dernière année fiscale à lire « AAAA » (incluse), sinon jusqu'à la fin
     * @param int|null    $centreId  limiter à un centre (admin) ; null = tous les centres
     *
     * @return array{lues: int, importees: int, rejetees: int, ignorees: int, contribuables_crees: int, sans_centre: int,
     *               assujettissements: int, erreurs: array<int, string>, centres_non_resolus: array<string, int>, tables_absentes: array<int, string>}
     */
    public function importer(?string $depuis = null, ?string $jusqua = null, ?int $centreId = null): array
    {
        foreach (['depuis' => $depuis, 'jusqua' => $jusqua] as $nom => $valeur) {
            if ($valeur !== null && ! preg_match(self::ANNEE_REGEX, $valeur)) {
                throw new InvalidArgumentException("Année « {$nom} » invalide : utilisez 4 chiffres (ex. 2025).");
            }
        }

        $stats = [
            'lues' => 0, 'importees' => 0, 'rejetees' => 0, 'ignorees' => 0, 'contribuables_crees' => 0, 'sans_centre' => 0,
            'assujettissements' => 0, 'erreurs' => [], 'centres_non_resolus' => [], 'tables_absentes' => [],
        ];

        $resolver = CentreResolver::depuisBase();

        DB::transaction(function () use ($depuis, $jusqua, $centreId, $resolver, &$stats) {
            $this->importerContribuables($resolver, $centreId, $stats);

            // Contribuables locaux (pour un admin : ceux de son centre seulement)
            $ids = ($centreId === null ? Contribuable::query() : Contribuable::where('centre_id', $centreId))->pluck('id', 'nif')->all();

            $this->importerAssujettissements($ids, $depuis, $jusqua, $stats);
            $this->importerDeclarations($ids, $depuis, $jusqua, $centreId, $stats);
        });

        return $stats;
    }

    // ------------------------------------------------------------------
    //  1. Contribuables
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $stats */
    private function importerContribuables(CentreResolver $resolver, ?int $centreId, array &$stats): void
    {
        $cfg = (array) config('dgi.source.contribuables');
        $table = (string) $cfg['table'];

        if (! $this->tableExiste($table)) {
            $stats['tables_absentes'][] = $table;

            return;
        }

        $col = $this->colonnes($table, (array) $cfg['colonnes'], ['nif']);
        $select = [];
        foreach ($col as $champ => $nom) {
            if ($nom !== null) {
                $select[] = $nom . ' as ' . $champ;
            }
        }

        $locaux = Contribuable::get(['id', 'nif', 'nom', 'centre_id', 'etat', 'date_cessation', 'date_radiation', 'date_blocage'])->keyBy('nif');
        $nouveaux = [];
        $now = now()->toDateTimeString();

        foreach ($this->connexion()->table($table)->select($select)->cursor() as $row) {
            $nif = Nif::normaliser($row->nif ?? '');
            if ($nif === '' || strlen($nif) > 30) {
                continue;
            }

            $nom = trim((string) ($row->nom ?? ''));
            $brut = trim((string) ($row->centre_code ?? '')) !== '' ? trim((string) $row->centre_code) : trim((string) ($row->centre ?? ''));
            $centreLigne = $resolver->resoudre($row->centre ?? null, $row->centre_code ?? null);

            if ($brut !== '' && $centreLigne === null) {
                $stats['centres_non_resolus'][$brut] = ($stats['centres_non_resolus'][$brut] ?? 0) + 1;
            }

            // Admin : on ne garde que les contribuables de son centre
            if ($centreId !== null && $centreLigne !== $centreId) {
                $stats['ignorees']++;
                continue;
            }

            $valeurs = [
                'etat' => is_numeric($row->etat ?? null) ? (int) $row->etat : null,
                'date_cessation' => self::date($row->date_cessation ?? null),
                'date_radiation' => self::date($row->date_radiation ?? null),
                'date_blocage' => self::date($row->date_blocage ?? null),
            ];

            $existant = $locaux->get($nif);

            if ($existant) {
                // La base source fait foi pour le nom, le centre (quand elle le fournit), l'état et les dates
                $maj = $valeurs;
                if ($nom !== '') {
                    $maj['nom'] = $nom;
                }
                if ($centreLigne !== null) {
                    $maj['centre_id'] = $centreLigne;
                }
                $existant->fill($maj);
                if ($existant->isDirty()) {
                    $existant->save();
                }
            } else {
                $nouveaux[] = ['nif' => $nif, 'nom' => $nom !== '' ? $nom : $nif, 'centre_id' => $centreLigne, 'created_at' => $now, 'updated_at' => $now] + $valeurs;
                $stats['contribuables_crees']++;
                if ($centreLigne === null) {
                    $stats['sans_centre']++;
                }

                if (count($nouveaux) >= self::LOT) {
                    DB::table('contribuables')->insert($nouveaux);
                    $nouveaux = [];
                }
            }
        }

        if ($nouveaux !== []) {
            DB::table('contribuables')->insert($nouveaux);
        }
    }

    // ------------------------------------------------------------------
    //  2. Assujettissements
    // ------------------------------------------------------------------

    /**
     * @param array<string, int> $ids  NIF => id des contribuables locaux
     * @param array<string, mixed> $stats
     */
    private function importerAssujettissements(array $ids, ?string $depuis, ?string $jusqua, array &$stats): void
    {
        $cfg = (array) config('dgi.source.assujettissements');
        $table = (string) $cfg['table'];

        if (! $this->tableExiste($table)) {
            $stats['tables_absentes'][] = $table;

            return;
        }

        $col = $this->colonnes($table, (array) $cfg['colonnes'], ['nif', 'annee', 'periodicite', 'impot']);
        $select = [];
        foreach ($col as $champ => $nom) {
            if ($nom !== null) {
                $select[] = $nom . ' as ' . $champ;
            }
        }

        $query = $this->connexion()->table($table)->select($select);
        if ($depuis !== null) {
            $query->where($col['annee'], '>=', $depuis);
        }
        if ($jusqua !== null) {
            $query->where($col['annee'], '<=', $jusqua);
        }

        $impots = (array) config('anomalies.defaillance.impots');
        $lot = [];
        $now = now()->toDateTimeString();

        foreach ($query->cursor() as $row) {
            $nif = Nif::normaliser($row->nif ?? '');
            $impot = strtoupper(trim((string) ($row->impot ?? '')));
            $annee = trim((string) ($row->annee ?? ''));
            $periodicite = is_numeric($row->periodicite ?? null) ? (int) $row->periodicite : 0;

            if (! isset($ids[$nif]) || ! in_array($impot, $impots, true) || ! preg_match(self::ANNEE_REGEX, $annee) || $periodicite < 1 || $periodicite > 5) {
                continue;
            }

            $lot[$ids[$nif] . '|' . $impot . '|' . $annee] = [
                'contribuable_id' => $ids[$nif],
                'type_impot' => $impot,
                'annee_fiscale' => (int) $annee,
                'periodicite' => $periodicite,
                'date_debut' => self::date($row->debut ?? null),
                'date_fin' => self::date($row->fin ?? null),
                'actif' => self::booleen($row->actif ?? 1),
                'exonere' => self::booleen($row->exonere ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $stats['assujettissements']++;

            if (count($lot) >= self::LOT) {
                $this->ecrireAssujettissements($lot);
                $lot = [];
            }
        }

        $this->ecrireAssujettissements($lot);
    }

    /** @param array<string, array<string, mixed>> $lot */
    private function ecrireAssujettissements(array $lot): void
    {
        if ($lot !== []) {
            Assujettissement::upsert(
                array_values($lot),
                ['contribuable_id', 'type_impot', 'annee_fiscale'],
                ['periodicite', 'date_debut', 'date_fin', 'actif', 'exonere', 'updated_at']
            );
        }
    }

    // ------------------------------------------------------------------
    //  3. Déclarations
    // ------------------------------------------------------------------

    /**
     * @param array<string, int> $ids  NIF => id des contribuables locaux
     * @param array<string, mixed> $stats
     */
    private function importerDeclarations(array &$ids, ?string $depuis, ?string $jusqua, ?int $centreId, array &$stats): void
    {
        $now = now()->toDateTimeString();
        $impots = (array) config('anomalies.defaillance.impots');
        $trouvee = false;

        foreach ((array) config('dgi.source.declarations') as $impot => $def) {
            $table = (string) $def['table'];

            if (! $this->tableExiste($table)) {
                $stats['tables_absentes'][] = $table;
                continue;
            }
            $trouvee = true;

            $col = $this->colonnes($table, (array) $def['colonnes'], ['nif', 'annee', 'ligne', 'montant', 'date_limite']);
            $w = fn (string $c) => $this->connexion()->getQueryGrammar()->wrap($c);

            $mois = $col['mois'] !== null ? 'coalesce(' . $w($col['mois']) . ', 0)' : '0';
            $version = $col['version'] !== null ? 'coalesce(' . $w($col['version']) . ', 0)' : '0';
            $montant = $col['montant_liquide'] !== null
                ? 'coalesce(' . $w($col['montant']) . ', ' . $w($col['montant_liquide']) . ')'
                : $w($col['montant']);
            $depot = $col['date_depot'] !== null ? 'max(' . $w($col['date_depot']) . ')' : 'null';

            // Une ligne par déclaration (et par version) : le chiffre d'affaires est celui de la ligne « ligne_ca »
            $query = $this->connexion()->table($table)
                ->selectRaw(
                    $w($col['nif']) . ' as nif, ' . $w($col['annee']) . ' as annee, ' . $mois . ' as mois, ' . $version . ' as version, '
                    . 'max(' . $w($col['date_limite']) . ') as date_limite, ' . $depot . ' as date_depot, '
                    . 'max(case when ' . $w($col['ligne']) . ' = ? then ' . $montant . ' end) as chiffre_affaires',
                    [(int) $def['ligne_ca']]
                )
                ->groupByRaw(implode(', ', array_filter([
                    $w($col['nif']),
                    $w($col['annee']),
                    // Pas de constante dans GROUP BY : PostgreSQL lirait « 0 » comme un numéro de colonne (erreur
                    // « GROUP BY position 0 is not in select list »). On ne groupe donc que sur les vraies colonnes.
                    $col['mois'] !== null ? $mois : null,
                    $col['version'] !== null ? $version : null,
                ])));

            if ($depuis !== null) {
                $query->where($col['annee'], '>=', $depuis);
            }
            if ($jusqua !== null) {
                $query->where($col['annee'], '<=', $jusqua);
            }

            $lot = [];

            foreach (self::retenirDerniereVersion($query->cursor()) as $row) {
                $stats['lues']++;

                [$ligne, $raison] = self::normaliserDeclaration($row, $impot, $impots);

                if ($ligne === null) {
                    $this->rejeter($stats, $row, $raison);
                    continue;
                }

                $nif = $ligne['nif'];

                if (! isset($ids[$nif])) {
                    if ($centreId !== null) {
                        $stats['ignorees']++; // admin : contribuable d'un autre centre ou inconnu
                        continue;
                    }

                    // Contribuable absent de la table des contribuables : créé sans centre (avertissement)
                    $ids[$nif] = Contribuable::create(['nif' => $nif, 'nom' => $nif])->id;
                    $stats['contribuables_crees']++;
                    $stats['sans_centre']++;
                }

                $lot[$ids[$nif] . '|' . $impot . '|' . $ligne['annee_fiscale'] . '|' . $ligne['mois']] = [
                    'contribuable_id' => $ids[$nif],
                    'type_impot' => $impot,
                    'annee_fiscale' => $ligne['annee_fiscale'],
                    'mois' => $ligne['mois'],
                    'chiffre_affaires' => $ligne['chiffre_affaires'],
                    'date_limite' => $ligne['date_limite'],
                    'date_depot' => $ligne['date_depot'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $stats['importees']++;

                if (count($lot) >= self::LOT) {
                    $this->ecrireDeclarations($lot);
                    $lot = [];
                }
            }

            $this->ecrireDeclarations($lot);
        }

        if (! $trouvee) {
            throw new InvalidArgumentException(
                'Aucune table de déclarations trouvée dans la base source (DGI_SOURCE_TABLE_IR, DGI_SOURCE_TABLE_TVA ou config/dgi.php).'
            );
        }
    }

    /** @param array<string, array<string, mixed>> $lot */
    private function ecrireDeclarations(array $lot): void
    {
        if ($lot !== []) {
            Declaration::upsert(
                array_values($lot),
                ['contribuable_id', 'type_impot', 'annee_fiscale', 'mois'],
                ['chiffre_affaires', 'date_limite', 'date_depot', 'updated_at']
            );
        }
    }

    // ------------------------------------------------------------------
    //  Fonctions pures (sans accès base) : testables seules
    // ------------------------------------------------------------------

    /**
     * Ne garde, pour chaque déclaration (NIF, année, mois), que la ligne de la DERNIÈRE version (déclarations rectificatives).
     *
     * @param iterable<object> $rows champs : nif, annee, mois, version, date_limite, date_depot, chiffre_affaires
     * @return array<string, object>
     */
    public static function retenirDerniereVersion(iterable $rows): array
    {
        $retenues = [];

        foreach ($rows as $row) {
            $cle = trim((string) $row->nif) . '|' . trim((string) $row->annee) . '|' . (int) $row->mois;

            if (! isset($retenues[$cle]) || (int) $row->version > (int) $retenues[$cle]->version) {
                $retenues[$cle] = $row;
            }
        }

        return $retenues;
    }

    /**
     * Valide et normalise une déclaration lue dans la base source.
     *
     * @param object            $row    champs : nif, annee, mois, date_limite, date_depot, chiffre_affaires
     * @param array<int,string> $impots impôts autorisés
     * @return array{0: array<string, mixed>|null, 1: string|null}  [déclaration normalisée, null] ou [null, raison du rejet]
     */
    public static function normaliserDeclaration(object $row, string $impot, array $impots): array
    {
        $nif = Nif::normaliser($row->nif ?? '');
        $annee = trim((string) ($row->annee ?? ''));
        $mois = trim((string) ($row->mois ?? '0'));

        if ($nif === '' || strlen($nif) > 30) {
            return [null, 'NIF vide ou trop long.'];
        }
        if (! in_array($impot, $impots, true)) {
            return [null, 'type d\'impôt « ' . $impot . ' » inconnu (attendu : ' . implode(', ', $impots) . ').'];
        }
        if (! preg_match(self::ANNEE_REGEX, $annee) || (int) $annee < 1990 || (int) $annee > 2100) {
            return [null, 'année fiscale « ' . $annee . ' » invalide (4 chiffres, ex. 2025).'];
        }
        if (! ctype_digit($mois) || (int) $mois > 12) {
            return [null, 'mois « ' . $mois . ' » invalide (0 à 12).'];
        }

        $ca = null;
        $brut = trim((string) ($row->chiffre_affaires ?? ''));
        if ($brut !== '') {
            $ca = str_replace(',', '.', str_replace([' ', "\xC2\xA0"], '', $brut));

            if (! is_numeric($ca) || (float) $ca < 0) {
                return [null, 'chiffre d\'affaires « ' . $brut . ' » invalide.'];
            }
        }

        $limite = self::date($row->date_limite ?? null);
        if ($limite === false) {
            return [null, 'date limite « ' . trim((string) $row->date_limite) . ' » invalide.'];
        }

        return [[
            'nif' => $nif,
            'annee_fiscale' => (int) $annee,
            'mois' => (int) $mois,
            'chiffre_affaires' => $ca,
            'date_limite' => $limite,
            'date_depot' => self::date($row->date_depot ?? null) ?: null,
        ], null];
    }

    /**
     * « AAAA-MM-JJ » (ou « AAAA-MM-JJ HH:MM:SS ») → « AAAA-MM-JJ » ; vide → null ; invalide → false.
     */
    public static function date(mixed $valeur): string|null|false
    {
        $brut = trim((string) ($valeur ?? ''));

        if ($brut === '') {
            return null;
        }

        $jour = substr($brut, 0, 10);
        $d = DateTime::createFromFormat('!Y-m-d', $jour);

        return ($d !== false && $d->format('Y-m-d') === $jour) ? $jour : false;
    }

    public static function booleen(mixed $valeur): bool
    {
        return in_array(strtolower(trim((string) $valeur)), ['1', 't', 'true', 'o', 'oui', 'y', 'yes'], true);
    }

    // ------------------------------------------------------------------
    //  Accès à la base source
    // ------------------------------------------------------------------

    private function tableExiste(string $table): bool
    {
        return $table !== '' && Schema::connection((string) config('dgi.source.connection'))->hasTable($table);
    }

    /**
     * Retrouve les vrais noms de colonnes (sans tenir compte de la casse : FISCAL_NO ou fiscal_no).
     *
     * @param array<string, string|null> $voulues  champ => nom de colonne configuré
     * @param array<int, string>         $obligatoires champs qui doivent exister
     * @return array<string, string|null>  champ => vrai nom (null si non configuré ou absent et facultatif)
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
            if ($nom === null || $nom === '') {
                $resultat[$champ] = null;
                continue;
            }

            $resultat[$champ] = $reelles[mb_strtolower((string) $nom)] ?? null;

            if ($resultat[$champ] === null && in_array($champ, $obligatoires, true)) {
                $manquantes[] = $nom;
            }
        }

        if ($manquantes !== []) {
            throw new InvalidArgumentException(
                "Colonnes introuvables dans la table « {$table} » : " . implode(', ', $manquantes)
                . '. Corrigez config/dgi.php (section source) ou le .env.'
            );
        }

        return $resultat;
    }

    /** @param array<string, mixed> $stats */
    private function rejeter(array &$stats, object $row, ?string $raison): void
    {
        $stats['rejetees']++;

        if (count($stats['erreurs']) < self::MAX_ERRORS) {
            $stats['erreurs'][] = sprintf(
                'NIF « %s », année %s, mois %s : %s',
                trim((string) ($row->nif ?? '')),
                trim((string) ($row->annee ?? '')),
                trim((string) ($row->mois ?? '0')),
                $raison
            );
        }
    }
}