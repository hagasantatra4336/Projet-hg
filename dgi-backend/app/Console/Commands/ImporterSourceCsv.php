<?php

namespace App\Console\Commands;

use App\Support\Nif;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Charge des fichiers CSV dans la BASE SOURCE (celle que lit dgi:importer-source), au format « une ligne par ligne de formulaire » :
 *   contribuables.csv      NIF;RAISON_SOCIALE;CENTRE_GEST;ETAT
 *   declarations-ir.csv    FISCAL_NO;ANNEE;IRD_LINE_NO;MONTANT_SOUMIS;DATE_ECHEANCE;DATE_RECEPTION;MONTANT_LIQUIDE
 *   declarations-tva.csv   FISCAL_NO;ANNEE;MOIS;IRD_LINE_NO;MONTANT_SOUMIS;DATE_ECHEANCE;DATE_RECEPTION;MONTANT_LIQUIDE
 *   recoupement.csv        NIF;ANNEE;PRESTATIONS_DE_SERVICE;PRODUITS_LOCAUX_VENDUS;EXPORTATIONS;MARCHER_PUBLIC;MONTANT_TOTAL;TVA_DED_ACHAT;TVA_DED_IMP
 *   dau.csv                NIF;ANNEE;MODELE;TVA;VALEUR_CAF
 * (en-têtes sans accent près, séparateur « ; » ou « , », dates AAAA-MM-JJ ou JJ/MM/AAAA ; les colonnes facultatives absentes sont laissées telles quelles).
 *
 *   php artisan dgi:source-importer-csv --dossier="D:/Nouveau dossier"
 *   puis : php artisan dgi:importer-source
 *          php artisan dgi:importer-risque --analyser
 *
 * Les lignes des NIF présents dans les fichiers sont remplacées, jamais dupliquées. Refus d'écrire dans une base source
 * dont le nom ne contient ni « test », ni « source », ni « demo », sauf --force.
 */
class ImporterSourceCsv extends Command
{
    protected $signature = 'dgi:source-importer-csv
        {--dossier= : Dossier contenant les fichiers CSV (défaut : dossier courant)}
        {--force : Autorise l\'écriture si le nom de la base source ne contient ni « test », ni « source », ni « demo »}';

    protected $description = 'Charge des CSV (contribuables, déclarations IR/TVA, recoupement, DAU) dans la base source de test';

    private const MAX_ERREURS = 15;

    private const FICHIERS = [
        'contribuables' => 'contribuables.csv',
        'IR' => 'declarations-ir.csv',
        'TVA' => 'declarations-tva.csv',
        'recoupement' => 'recoupement.csv',
        'dau' => 'dau.csv',
    ];

    private const REQUIS = [
        'contribuables' => ['nif'],
        'IR' => ['nif', 'annee', 'ligne', 'montant'],
        'TVA' => ['nif', 'annee', 'mois', 'ligne', 'montant'],
        'recoupement' => ['nif', 'annee'],
        'dau' => ['nif', 'annee', 'modele', 'tva', 'valeur_caf'],
    ];

    private ConnectionInterface $cx;

    private string $connexion = '';

    private int $rejetees = 0;

    /** @var array<int, string> */
    private array $erreurs = [];

    private int $numero = 0;

    /** @var array<string, array<string, string|null>> */
    private array $colonnes = [];

    public function handle(): int
    {
        $this->connexion = (string) config('dgi.source.connection');
        $this->cx = DB::connection($this->connexion);
        $base = (string) $this->cx->getDatabaseName();

        if (! $this->option('force') && ! preg_match('/test|source|demo/i', $base)) {
            $this->error("Refus : la base source « {$base} » ne contient ni « test », ni « source », ni « demo » dans son nom.");
            $this->line('Pour écrire malgré tout, ajoutez --force.');

            return self::FAILURE;
        }

        $dossier = rtrim((string) ($this->option('dossier') ?: getcwd()), '/\\');
        if (! is_dir($dossier)) {
            $this->error("Dossier introuvable : {$dossier}");

            return self::FAILURE;
        }

        try {
            $this->colonnes = $this->colonnes();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $fichiers = [];
        foreach (self::FICHIERS as $cle => $nom) {
            $chemin = $dossier.DIRECTORY_SEPARATOR.$nom;
            if (is_file($chemin)) {
                $fichiers[$cle] = $chemin;
            } else {
                $this->line("  {$nom} : absent, table non touchée.");
            }
        }
        if ($fichiers === []) {
            $this->error('Aucun fichier attendu dans '.$dossier.' : '.implode(', ', self::FICHIERS).'.');

            return self::FAILURE;
        }

        $lots = [];
        foreach ($fichiers as $cle => $chemin) {
            $lots[$cle] = $this->lire($cle, $chemin);
            if ($lots[$cle] === null) {
                return self::FAILURE;
            }
        }

        if ($this->rejetees > 0) {
            $this->warn("{$this->rejetees} ligne(s) rejetée(s) :");
            foreach ($this->erreurs as $erreur) {
                $this->line("  {$erreur}");
            }
        }

        try {
            $this->cx->transaction(function () use ($lots) {
                $tables = $this->tables();

                foreach ($lots as $cle => $lignes) {
                    $colNif = $this->colonnes[$cle]['nif'];
                    $nifs = array_values(array_unique(array_map(fn ($l) => (string) $l[$colNif], $lignes)));

                    foreach (array_chunk($nifs, 500) as $paquet) {
                        $this->cx->table($tables[$cle])->whereIn($colNif, $paquet)->delete();
                    }
                    foreach (array_chunk($lignes, 400) as $paquet) {
                        $this->cx->table($tables[$cle])->insert($paquet);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->error("Échec de l'écriture dans la base source : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Base source « '.$base.' » alimentée :');
        foreach ($lots as $cle => $lignes) {
            $this->line('  '.self::FICHIERS[$cle].' : '.count($lignes).' ligne(s)');
        }

        $this->line('Étapes suivantes :');
        $this->line('  php artisan dgi:importer-source');
        $this->line('  php artisan dgi:importer-risque --analyser');

        return self::SUCCESS;
    }

    /** @return array{contribuables: string, IR: string, TVA: string, recoupement: string, dau: string} */
    private function tables(): array
    {
        return [
            'contribuables' => (string) config('dgi.source.contribuables.table'),
            'IR' => (string) config('dgi.source.declarations.IR.table'),
            'TVA' => (string) config('dgi.source.declarations.TVA.table'),
            'recoupement' => (string) config('risque.recoupement.table'),
            'dau' => (string) config('risque.dau.table'),
        ];
    }

    /**
     * Noms réels des colonnes de chaque table source, par champ logique (config/dgi.php et config/risque.php).
     *
     * @return array<string, array<string, string|null>>
     */
    private function colonnes(): array
    {
        $defs = [
            'contribuables' => (array) config('dgi.source.contribuables.colonnes'),
            'IR' => (array) config('dgi.source.declarations.IR.colonnes'),
            'TVA' => (array) config('dgi.source.declarations.TVA.colonnes'),
            'recoupement' => (array) config('risque.recoupement.colonnes'),
            'dau' => (array) config('risque.dau.colonnes'),
        ];

        $tables = $this->tables();
        $resultat = [];

        foreach ($defs as $cle => $voulues) {
            if (! Schema::connection($this->connexion)->hasTable($tables[$cle])) {
                throw new \InvalidArgumentException("Table « {$tables[$cle]} » introuvable dans la base source.");
            }

            $reelles = [];
            foreach (Schema::connection($this->connexion)->getColumnListing($tables[$cle]) as $nom) {
                $reelles[mb_strtolower((string) $nom)] = (string) $nom;
            }

            $resultat[$cle] = [];
            foreach ($voulues as $champ => $nom) {
                $resultat[$cle][$champ] = ($nom === null || $nom === '') ? null : ($reelles[mb_strtolower((string) $nom)] ?? null);
            }

            foreach (self::REQUIS[$cle] as $champ) {
                if (($resultat[$cle][$champ] ?? null) === null) {
                    throw new \InvalidArgumentException("Colonne « {$champ} » introuvable dans « {$tables[$cle]} ».");
                }
            }
        }

        return $resultat;
    }

    /**
     * @return array<int, array<string, mixed>>|null null si les colonnes obligatoires manquent
     */
    private function lire(string $cle, string $chemin): ?array
    {
        $this->numero = 1;
        $handle = fopen($chemin, 'r');
        if ($handle === false) {
            $this->error("Fichier illisible : {$chemin}");

            return null;
        }

        $premier = (string) fgets($handle);
        rewind($handle);
        $delimiteur = substr_count($premier, ';') >= substr_count($premier, ',') ? ';' : ',';

        $entete = fgetcsv($handle, 0, $delimiteur) ?: [];
        $cols = $this->entetes($entete);
        $manquantes = array_diff(self::REQUIS[$cle], $cols);
        if ($manquantes !== []) {
            fclose($handle);
            $this->error(basename($chemin).' : colonne(s) manquante(s) : '.implode(', ', $manquantes)
                .'. Attendu : '.implode(';', self::REQUIS[$cle]));

            return null;
        }

        $lignes = [];
        while (($row = fgetcsv($handle, 0, $delimiteur)) !== false) {
            $this->numero++;

            if ($row === [null] || trim(implode('', $row)) === '') {
                continue;
            }

            $cells = array_map(fn ($v) => trim((string) $v), array_pad(array_slice($row, 0, count($cols)), count($cols), ''));
            $l = [];
            foreach ($cols as $i => $c) {
                $l[$c] = $cells[$i] ?? '';
            }

            $ligne = match ($cle) {
                'contribuables' => $this->ligneContribuable($l),
                'IR', 'TVA' => $this->ligneDeclaration($cle, $l),
                'recoupement' => $this->ligneRecoupement($l),
                'dau' => $this->ligneDau($l),
            };

            if ($ligne !== null) {
                $lignes[] = $ligne;
            }
        }

        fclose($handle);

        return $this->uniformiser($lignes);
    }

    /** @param array<int, string> $entete @return array<int, string> */
    private function entetes(array $entete): array
    {
        return array_map(function ($h) {
            $c = Str::of((string) $h)->replace("\xEF\xBB\xBF", '')->trim()->lower()->ascii()->replace([' ', '-'], '_')->toString();

            return match ($c) {
                'fiscal_no' => 'nif',
                'raison_sociale' => 'nom',
                'centre_gest' => 'centre',
                'ird_line_no' => 'ligne',
                'montant_soumis' => 'montant',
                'date_echeance' => 'date_limite',
                'date_reception' => 'date_depot',
                'marcher_public' => 'marche_public',
                default => $c,
            };
        }, $entete);
    }

    /** @param array<string, string> $l @return array<string, mixed>|null */
    private function ligneContribuable(array $l): ?array
    {
        $nif = $this->nif($l['nif']);
        if ($nif === null) {
            return null;
        }

        $c = $this->colonnes['contribuables'];
        $ligne = [$c['nif'] => $nif];

        foreach (['nom', 'centre', 'etat', 'date_cessation', 'date_radie', 'date_bloque'] as $champ) {
            if (($c[$champ] ?? null) === null || ($l[$champ] ?? '') === '') {
                continue;
            }

            $valeur = match ($champ) {
                'etat' => (int) $l[$champ],
                'date_cessation', 'date_radie', 'date_bloque' => $this->date($l[$champ]),
                default => $l[$champ],
            };

            if ($valeur === null) {
                $this->rejeter("{$champ} « {$l[$champ]} » invalide (AAAA-MM-JJ ou JJ/MM/AAAA).");

                return null;
            }
            $ligne[$c[$champ]] = $valeur;
        }

        return $ligne;
    }

    /** @param array<string, string> $l @return array<string, mixed>|null */
    private function ligneDeclaration(string $cle, array $l): ?array
    {
        $nif = $this->nif($l['nif']);
        if ($nif === null) {
            return null;
        }

        $annee = $l['annee'];
        if (! preg_match('/^\d{4}$/', $annee) || (int) $annee < 1990 || (int) $annee > 2100) {
            $this->rejeter("annee « {$annee} » invalide (4 chiffres, ex. 2025).");

            return null;
        }

        $numeroLigne = $l['ligne'];
        if (! ctype_digit($numeroLigne) || (int) $numeroLigne < 1) {
            $this->rejeter("IRD_LINE_NO « {$numeroLigne} » invalide (numéro de ligne du formulaire).");

            return null;
        }

        $montant = $this->nombre($l['montant']);
        if ($montant === null) {
            $this->rejeter("montant « {$l['montant']} » invalide.");

            return null;
        }

        $c = $this->colonnes[$cle];
        $ligne = [
            $c['nif'] => $nif,
            $c['annee'] => (int) $annee,
            $c['ligne'] => (int) $numeroLigne,
            $c['montant'] => $montant,
        ];

        if (($c['mois'] ?? null) !== null) {
            $mois = $cle === 'TVA' ? ($l['mois'] ?? '') : '';

            if ($mois === '') {
                $ligne[$c['mois']] = null; // déclaration annuelle (IR)
            } elseif (! ctype_digit($mois) || (int) $mois < 1 || (int) $mois > 12) {
                $this->rejeter("mois « {$mois} » invalide (1 à 12).");

                return null;
            } else {
                $ligne[$c['mois']] = (int) $mois;
            }
        }

        if (($c['montant_liquide'] ?? null) !== null) {
            $liquide = ($l['montant_liquide'] ?? '') === '' ? $montant : $this->nombre($l['montant_liquide']);
            if ($liquide === null) {
                $this->rejeter("MONTANT_LIQUIDE « {$l['montant_liquide']} » invalide.");

                return null;
            }
            $ligne[$c['montant_liquide']] = $liquide;
        }

        if (($c['version'] ?? null) !== null) {
            $ligne[$c['version']] = 1;
        }

        foreach (['date_limite', 'date_depot'] as $champ) {
            if (($c[$champ] ?? null) === null || ($l[$champ] ?? '') === '') {
                continue;
            }

            $date = $this->date($l[$champ]);
            if ($date === null) {
                $this->rejeter("{$champ} « {$l[$champ]} » invalide (AAAA-MM-JJ ou JJ/MM/AAAA).");

                return null;
            }
            $ligne[$c[$champ]] = $date;
        }

        return $ligne;
    }

    /** @param array<string, string> $l @return array<string, mixed>|null */
    private function ligneRecoupement(array $l): ?array
    {
        $nif = $this->nif($l['nif']);
        if ($nif === null) {
            return null;
        }

        $annee = $l['annee'];
        if (! preg_match('/^\d{4}$/', $annee) || (int) $annee < 1990 || (int) $annee > 2100) {
            $this->rejeter("annee « {$annee} » invalide (4 chiffres).");

            return null;
        }

        $c = $this->colonnes['recoupement'];
        $ligne = [$c['nif'] => $nif, $c['annee'] => (int) $annee];

        foreach ($c as $champ => $nom) {
            if ($nom === null || in_array($champ, ['nif', 'annee'], true)) {
                continue;
            }

            if (($l[$champ] ?? '') === '') {
                $ligne[$nom] = 0;

                continue;
            }

            $montant = $this->nombre($l[$champ]);
            if ($montant === null) {
                $this->rejeter("{$champ} « {$l[$champ]} » invalide.");

                return null;
            }
            $ligne[$nom] = $montant;
        }

        return $ligne;
    }

    /** @param array<string, string> $l @return array<string, mixed>|null */
    private function ligneDau(array $l): ?array
    {
        $nif = $this->nif($l['nif']);
        if ($nif === null) {
            return null;
        }

        $annee = $l['annee'];
        if (! preg_match('/^\d{4}$/', $annee) || (int) $annee < 1990 || (int) $annee > 2100) {
            $this->rejeter("annee « {$annee} » invalide (4 chiffres).");

            return null;
        }

        $modele = strtoupper($l['modele']);
        if (! in_array($modele, ['IM4', 'EX1'], true)) {
            $this->rejeter("MODELE « {$l['modele']} » inconnu (attendu : IM4 ou EX1).");

            return null;
        }

        $tva = $this->nombre($l['tva']);
        $caf = $this->nombre($l['valeur_caf']);
        if ($tva === null || $caf === null) {
            $this->rejeter('TVA ou VALEUR_CAF invalide.');

            return null;
        }

        $c = $this->colonnes['dau'];

        return [
            $c['nif'] => $nif,
            $c['annee'] => (int) $annee,
            $c['modele'] => $modele,
            $c['tva'] => $tva,
            $c['valeur_caf'] => $caf,
        ];
    }

    /**
     * Toutes les lignes d'un fichier doivent avoir exactement les mêmes colonnes (insertion groupée) :
     * les champs absents d'une ligne sont complétés par null.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    private function uniformiser(array $lignes): array
    {
        $cles = [];
        foreach ($lignes as $ligne) {
            foreach (array_keys($ligne) as $cle) {
                $cles[$cle] = true;
            }
        }
        $cles = array_keys($cles);

        return array_map(function (array $ligne) use ($cles) {
            $complete = [];
            foreach ($cles as $cle) {
                $complete[$cle] = $ligne[$cle] ?? null;
            }

            return $complete;
        }, $lignes);
    }

    private function nif(string $valeur): ?string
    {
        $nif = Nif::normaliser($valeur);

        if ($nif === '' || strlen($nif) > 30) {
            $this->rejeter('NIF vide ou trop long.');

            return null;
        }

        return $nif;
    }

    private function nombre(string $valeur): ?float
    {
        $v = str_replace([' ', "\xC2\xA0"], '', $valeur);
        $v = str_replace(',', '.', $v);

        if ($v === '' || ! is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    private function date(string $valeur): ?string
    {
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $d = DateTime::createFromFormat('!'.$format, $valeur);

            if ($d !== false && $d->format($format) === $valeur) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private function rejeter(string $raison): void
    {
        $this->rejetees++;
        if (count($this->erreurs) < self::MAX_ERREURS) {
            $this->erreurs[] = "Ligne {$this->numero} : {$raison}";
        }
    }
}
