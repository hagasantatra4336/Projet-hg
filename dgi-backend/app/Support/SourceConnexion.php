<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accès aux bases sources : un « groupe » = un ensemble de tables (declarations, assujettissements,
 * contribuables, centres, agents, recoupement, dau) qui peut vivre dans sa propre base.
 *
 *   config/dgi.php        : source.connections.<groupe> = nom de la connexion Laravel à utiliser
 *                           (vide => source.connection, la connexion commune)
 *   config/database.php   : connexions source_<groupe>, paramétrées par SOURCE_<GROUPE>_DB_*
 *                           (une variable absente reprend la valeur de SOURCE_DB_*)
 *
 * Oracle : yajra/laravel-oci8 filtre les métadonnées (hasTable, liste des colonnes) sur
 * « owner = utilisateur de la connexion ». Si les tables appartiennent à un autre schéma,
 * renseignez SOURCE_<GROUPE>_DB_SCHEMA (ou SOURCE_DB_SCHEMA) : les tables sont alors lues en
 * « SCHEMA.TABLE » et les métadonnées interrogées sur ce propriétaire.
 */
class SourceConnexion
{
    /** Nom de la connexion Laravel d'un groupe (repli sur la connexion commune). */
    public static function nom(string $groupe): string
    {
        $nom = trim((string) config("dgi.source.connections.{$groupe}"));

        return $nom !== '' ? $nom : (string) config('dgi.source.connection');
    }

    public static function db(string $groupe): ConnectionInterface
    {
        return DB::connection(self::nom($groupe));
    }

    /** Propriétaire Oracle des tables du groupe (null : l'utilisateur de la connexion). */
    public static function schema(string $groupe): ?string
    {
        $schema = trim((string) config('database.connections.'.self::nom($groupe).'.schema'));

        return $schema !== '' ? $schema : null;
    }

    /** Nom de table à utiliser dans les requêtes (qualifié si un schéma Oracle est configuré). */
    public static function table(string $groupe, string $table): string
    {
        $table = trim($table);

        if ($table === '' || str_contains($table, '.')) {
            return $table;
        }

        $schema = self::schema($groupe);

        return $schema === null ? $table : $schema.'.'.$table;
    }

    public static function tableExiste(string $groupe, string $table): bool
    {
        $table = trim($table);

        if ($table === '') {
            return false;
        }

        if (! self::estOracle($groupe)) {
            return Schema::connection(self::nom($groupe))->hasTable($table);
        }

        [$proprietaire, $nom] = self::proprietaireEtTable($groupe, $table);

        return (int) self::db($groupe)->scalar(
            'select count(*) from all_objects where owner = upper(?) and object_name = upper(?) '
            ."and object_type in ('TABLE', 'VIEW', 'MATERIALIZED VIEW')",
            [$proprietaire, $nom]
        ) > 0;
    }

    /**
     * Noms réels des colonnes, avec leur casse d'origine (Oracle renvoie des majuscules).
     *
     * @return array<int, string>
     */
    public static function colonnes(string $groupe, string $table): array
    {
        $table = trim($table);

        if ($table === '') {
            return [];
        }

        if (! self::estOracle($groupe)) {
            return array_values(array_map('strval', Schema::connection(self::nom($groupe))->getColumnListing($table)));
        }

        [$proprietaire, $nom] = self::proprietaireEtTable($groupe, $table);

        $lignes = self::db($groupe)->select(
            'select column_name from all_tab_columns where owner = upper(?) and table_name = upper(?) order by column_id',
            [$proprietaire, $nom]
        );

        $colonnes = [];
        foreach ($lignes as $ligne) {
            $valeurs = array_values((array) $ligne);
            if ($valeurs !== []) {
                $colonnes[] = (string) $valeurs[0];
            }
        }

        return $colonnes;
    }

    private static function estOracle(string $groupe): bool
    {
        return self::db($groupe)->getDriverName() === 'oracle';
    }

    /** @return array{0: string, 1: string} propriétaire et nom de la table */
    private static function proprietaireEtTable(string $groupe, string $table): array
    {
        if (str_contains($table, '.')) {
            [$schema, $nom] = explode('.', $table, 2);

            return [$schema, $nom];
        }

        return [self::schema($groupe) ?? (string) self::db($groupe)->getConfig('username'), $table];
    }
}
