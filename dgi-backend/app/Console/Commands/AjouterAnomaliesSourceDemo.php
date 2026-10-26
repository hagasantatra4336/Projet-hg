<?php

namespace App\Console\Commands;

use App\Models\Centre;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Insère dans la BASE SOURCE des contribuables de démonstration qui déclenchent chacun UN critère de risque :
 *   9000000101 → R1 (CA IR 100 M contre CA TVA 30 M)
 *   9000000201 → R2 (résultat déficitaire 2024-2026 : orange 2025, rouge 2026)
 *   9000000301 → R3 (crédit de TVA sur 9 mois de 2026)
 *   9000000501 → R5 (recoupement 40 M contre CA déclaré 100 M)
 *   9000000601 → R6 (DAU IM4 40 M contre 2 M de TVA déductible sur biens importés)
 *   9000000701 → R7 (DAU EX1 100 M contre 30 M de CA taxable à l'exportation)
 *
 * Toutes les TVA de janvier au dernier mois échu sont déclarées : ces dossiers ne créent donc pas de défaut de dépôt (R4).
 * Seules les lignes de ces six NIF sont supprimées puis réécrites ; le reste de la base source n'est jamais touché.
 *
 *   php artisan dgi:source-anomalies-demo
 *   php artisan dgi:source-anomalies-demo --annee=2025
 *   php artisan dgi:source-anomalies-demo --force     # base source dont le nom ne contient ni « test », ni « source »
 *
 * Étapes suivantes :
 *   php artisan dgi:importer-source
 *   php artisan dgi:importer-risque --analyser
 */
class AjouterAnomaliesSourceDemo extends Command
{
    protected $signature = 'dgi:source-anomalies-demo
        {--annee= : Exercice des exemples (défaut : année en cours)}
        {--force : Autorise l\'écriture si le nom de la base source ne contient ni « test », ni « source », ni « demo »}';

    protected $description = 'Insère dans la base source des exemples d\'anomalies R1, R2, R3, R5, R6 et R7';

    private const NIFS = [
        'R1' => '9000000101',
        'R2' => '9000000201',
        'R3' => '9000000301',
        'R5' => '9000000501',
        'R6' => '9000000601',
        'R7' => '9000000701',
    ];

    private string $connexion = '';

    public function handle(): int
    {
        $this->connexion = (string) config('dgi.source.connection');
        $cx = DB::connection($this->connexion);
        $base = (string) $cx->getDatabaseName();

        if (! $this->option('force') && ! preg_match('/test|source|demo/i', $base)) {
            $this->error("Refus : la base source « {$base} » ne contient ni « test », ni « source », ni « demo » dans son nom.");
            $this->line('Pour écrire malgré tout dans une base de test, ajoutez --force.');

            return self::FAILURE;
        }

        $annee = (int) ($this->option('annee') ?: now()->format('Y'));
        if ($annee < 1990 || $annee > (int) now()->format('Y')) {
            $this->error('--annee doit être une année passée ou l\'année en cours.');

            return self::FAILURE;
        }

        $dernierMois = $annee === (int) now()->format('Y') ? (int) now()->subMonthNoOverflow()->format('n') : 12;
        if ($dernierMois < 1) {
            $dernierMois = 12;
        }

        $tables = $this->tables();
        foreach (['contribuables' => $tables['contribuables'], 'IR' => $tables['IR'], 'TVA' => $tables['TVA'],
            'recoupement' => $tables['recoupement'], 'dau' => $tables['dau']] as $nom => $table) {
            if ($table === '' || ! Schema::connection($this->connexion)->hasTable($table)) {
                $this->error("Table « {$nom} » introuvable dans la base source (« {$table} ») : vérifiez config/dgi.php et config/risque.php.");

                return self::FAILURE;
            }
        }

        $centre = $this->centreSource($cx);
        if ($centre === null) {
            $this->error("Aucun centre de l'application ne correspond à un CENTRE_GEST de la base source.");
            $this->line("Créez/importez d'abord les centres : php artisan dgi:importer-centres");

            return self::FAILURE;
        }

        $cols = $this->colonnes($cx);
        $jeu = $this->jeu($annee, $dernierMois, $centre);

        try {
            $cx->transaction(function () use ($cx, $tables, $cols, $jeu) {
                $nifs = array_values(self::NIFS);

                foreach (['contribuables' => $cols['contribuables']['nif'], 'IR' => $cols['IR']['nif'],
                    'TVA' => $cols['TVA']['nif'], 'recoupement' => $cols['recoupement']['nif'], 'dau' => $cols['dau']['nif']] as $cle => $colNif) {
                    $cx->table($tables[$cle])->whereIn($colNif, $nifs)->delete();
                }

                foreach (array_chunk($jeu['contribuables'], 200) as $lot) {
                    $cx->table($tables['contribuables'])->insert($lot);
                }
                foreach (array_chunk($jeu['ir'], 400) as $lot) {
                    $cx->table($tables['IR'])->insert($lot);
                }
                foreach (array_chunk($jeu['tva'], 400) as $lot) {
                    $cx->table($tables['TVA'])->insert($lot);
                }
                if ($jeu['recoupement'] !== []) {
                    $cx->table($tables['recoupement'])->insert($jeu['recoupement']);
                }
                if ($jeu['dau'] !== []) {
                    $cx->table($tables['dau'])->insert($jeu['dau']);
                }
            });
        } catch (Throwable $e) {
            $this->error("Échec de l'écriture dans la base source : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exemples insérés dans « %s » : 6 contribuables, %d déclaration(s) IR, %d déclaration(s) TVA, %d recoupement(s), %d DAU.',
            $base, count($jeu['ir']), count($jeu['tva']), count($jeu['recoupement']), count($jeu['dau'])
        ));
        foreach (self::NIFS as $regle => $nif) {
            $this->line("  {$regle} : {$nif} — " . $jeu['noms'][$nif]);
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
     * Retrouve, dans la table source des contribuables, un CENTRE_GEST qui correspond à un centre de l'application
     * (même nom ou même code), pour que l'import rattache les exemples à un centre visible.
     */
    private function centreSource(ConnectionInterface $cx): ?string
    {
        $table = (string) config('dgi.source.contribuables.table');
        $col = $this->colonnes($cx)['contribuables']['centre'];
        $centres = Centre::query()->get(['nom', 'code']);

        if ($centres->isEmpty()) {
            return null;
        }

        if ($col !== null) {
            $valeurs = $cx->table($table)->whereNotNull($col)->distinct()->pluck($col)->all();
            foreach ($valeurs as $valeur) {
                $v = mb_strtolower(trim((string) $valeur));
                foreach ($centres as $c) {
                    if ($v === mb_strtolower(trim((string) $c->nom))
                        || ($c->code !== null && $v === mb_strtolower(trim((string) $c->code)))) {
                        return (string) $valeur;
                    }
                }
            }
        }

        $premier = $centres->first();

        return $premier->code ?: $premier->nom;
    }

    /** @return array<string, array<string, string|null>> */
    private function colonnes(ConnectionInterface $cx): array
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

        $obligatoires = [
            'contribuables' => ['nif'],
            'IR' => ['nif', 'annee', 'ligne', 'montant', 'date_limite'],
            'TVA' => ['nif', 'annee', 'ligne', 'montant', 'date_limite'],
            'recoupement' => ['nif', 'annee'],
            'dau' => ['nif', 'annee', 'modele', 'tva', 'valeur_caf'],
        ];

        foreach ($defs as $cle => $voulues) {
            $reelles = [];
            foreach (Schema::connection($this->connexion)->getColumnListing($tables[$cle]) as $nom) {
                $reelles[mb_strtolower((string) $nom)] = (string) $nom;
            }

            $resultat[$cle] = [];
            foreach ($voulues as $champ => $nom) {
                $resultat[$cle][$champ] = ($nom === null || $nom === '') ? null : ($reelles[mb_strtolower((string) $nom)] ?? null);
            }

            foreach ($obligatoires[$cle] as $champ) {
                if (($resultat[$cle][$champ] ?? null) === null) {
                    throw new \InvalidArgumentException("Colonne « {$champ} » introuvable dans « {$tables[$cle]} ».");
                }
            }
        }

        return $resultat;
    }

    /**
     * Construit les lignes source (noms de colonnes réels) des six dossiers de démonstration.
     *
     * @return array{contribuables: array<int, array<string, mixed>>, ir: array<int, array<string, mixed>>,
     *               tva: array<int, array<string, mixed>>, recoupement: array<int, array<string, mixed>>,
     *               dau: array<int, array<string, mixed>>, noms: array<string, string>}
     */
    private function jeu(int $annee, int $dernierMois, string $centre): array
    {
        $cols = $this->colonnes(DB::connection($this->connexion));
        $mois = range(1, $dernierMois);
        $nbMois = count($mois);
        $parMois = fn (float $total): float => round($total / $nbMois, 2);

        $noms = [
            self::NIFS['R1'] => 'Démo R1 · CA IR 100 M contre CA TVA 30 M',
            self::NIFS['R2'] => 'Démo R2 · résultat déficitaire depuis 3 exercices',
            self::NIFS['R3'] => 'Démo R3 · crédit de TVA sur 9 mois',
            self::NIFS['R5'] => 'Démo R5 · recoupement 40 M contre CA déclaré 100 M',
            self::NIFS['R6'] => 'Démo R6 · importations DAU 40 M sans TVA déductible',
            self::NIFS['R7'] => 'Démo R7 · exportations DAU 100 M contre CA export 30 M',
        ];

        $jeu = [
            'contribuables' => [],
            'ir' => [],
            'tva' => [],
            'recoupement' => [],
            'dau' => [],
            'noms' => $noms,
        ];

        $cc = $cols['contribuables'];
        $ci = $cols['IR'];
        $ct = $cols['TVA'];

        foreach (self::NIFS as $nif) {
            $ligne = [$cc['nif'] => $nif, $cc['nom'] => $noms[$nif]];
            if (($cc['centre'] ?? null) !== null) {
                $ligne[$cc['centre']] = $centre;
            }
            if (($cc['etat'] ?? null) !== null) {
                $ligne[$cc['etat']] = 1;
            }
            $jeu['contribuables'][] = $ligne;
        }

        // R1 : CA IR 100 M contre 30 M de TVA sur l'exercice
        $this->ajouterIr($jeu['ir'], $ci, self::NIFS['R1'], $annee, 100_000_000, 15_000_000, 3_000_000);
        $this->ajouterTva($jeu['tva'], $ct, self::NIFS['R1'], $annee, $mois, ['ca' => $parMois(30_000_000), 'ca_taxable' => $parMois(30_000_000)]);

        // R2 : trois exercices déficitaires (2025 orange, 2026 rouge)
        for ($a = $annee - 2; $a <= $annee; $a++) {
            $this->ajouterIr($jeu['ir'], $ci, self::NIFS['R2'], $a, 80_000_000, -5_000_000, 0);
        }

        // R3 : crédit de TVA chaque mois, du premier au dernier mois déclaré
        $this->ajouterTva($jeu['tva'], $ct, self::NIFS['R3'], $annee, $mois, ['ca' => 2_000_000, 'credit_tva' => 1_000_000]);

        // R5 : CA déclaré 100 M (IR et TVA) contre 40 M de recoupement
        $this->ajouterIr($jeu['ir'], $ci, self::NIFS['R5'], $annee, 100_000_000, 20_000_000, 4_000_000);
        $this->ajouterTva($jeu['tva'], $ct, self::NIFS['R5'], $annee, $mois, ['ca' => $parMois(100_000_000), 'ca_taxable' => $parMois(100_000_000)]);
        $jeu['recoupement'][] = $this->ligneMontants($cols['recoupement'], self::NIFS['R5'], $annee, [
            'prestations_de_service' => 40_000_000, 'montant_total' => 40_000_000,
        ]);

        // R6 : TVA des DAU IM4 40 M contre 2 M de TVA déductible sur biens importés
        $this->ajouterTva($jeu['tva'], $ct, self::NIFS['R6'], $annee, $mois, ['ca' => 5_000_000], [
            $mois[0] => ['tva_deduc_bi' => 2_000_000],
        ]);
        $jeu['dau'][] = $this->ligneDau($cols['dau'], self::NIFS['R6'], $annee, 'IM4', 40_000_000, 0);

        // R7 : valeur CAF des DAU EX1 100 M contre 30 M de CA taxable à l'exportation
        $this->ajouterTva($jeu['tva'], $ct, self::NIFS['R7'], $annee, $mois, [
            'ca' => $parMois(30_000_000), 'ca_taxable' => $parMois(30_000_000), 'ca_taxable_export' => $parMois(30_000_000),
        ]);
        $jeu['dau'][] = $this->ligneDau($cols['dau'], self::NIFS['R7'], $annee, 'EX1', 0, 100_000_000);

        return $jeu;
    }

    /** @param array<int, array<string, mixed>> $lignes */
    private function ajouterIr(array &$lignes, array $col, string $nif, int $annee, float $ca, float $resultat, float $ir): void
    {
        $echeance = sprintf('%04d-05-15', $annee + 1);
        $depot = sprintf('%04d-05-10', $annee + 1);

        foreach (['ca' => $ca, 'resultat' => $resultat, 'ir_a_payer' => $ir] as $indicateur => $montant) {
            $numeros = (array) config("risque.lignes.IR.{$indicateur}");
            foreach ($numeros as $numero) {
                $ligne = [
                    $col['nif'] => $nif,
                    $col['annee'] => $annee,
                    $col['ligne'] => $numero,
                    $col['montant'] => $montant,
                    $col['date_limite'] => $echeance,
                    $col['date_depot'] => $depot,
                ];
                if (($col['mois'] ?? null) !== null) {
                    $ligne[$col['mois']] = null; // déclaration annuelle
                }
                if (($col['montant_liquide'] ?? null) !== null) {
                    $ligne[$col['montant_liquide']] = $montant;
                }
                if (($col['version'] ?? null) !== null) {
                    $ligne[$col['version']] = 1;
                }
                $lignes[] = $ligne;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lignes
     * @param array<int, int> $mois
     * @param array<string, float> $valeurs par mois
     * @param array<int, array<string, float>> $exceptions mois => indicateurs à remplacer
     */
    private function ajouterTva(array &$lignes, array $col, string $nif, int $annee, array $mois, array $valeurs, array $exceptions = []): void
    {
        foreach ($mois as $m) {
            $duMois = array_merge($valeurs, $exceptions[$m] ?? []);
            $suivant = (new DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $m)))->modify('+1 month');
            $echeance = $suivant->setDate((int) $suivant->format('Y'), (int) $suivant->format('n'), 15)->format('Y-m-d');
            $depot = $suivant->setDate((int) $suivant->format('Y'), (int) $suivant->format('n'), 10)->format('Y-m-d');

            foreach ($duMois as $indicateur => $montant) {
                $numeros = (array) config("risque.lignes.TVA.{$indicateur}");
                foreach ($numeros as $numero) {
                    $ligne = [
                        $col['nif'] => $nif,
                        $col['annee'] => $annee,
                        $col['ligne'] => $numero,
                        $col['montant'] => $montant,
                        $col['date_limite'] => $echeance,
                        $col['date_depot'] => $depot,
                    ];
                    if (($col['mois'] ?? null) !== null) {
                        $ligne[$col['mois']] = $m;
                    }
                    if (($col['montant_liquide'] ?? null) !== null) {
                        $ligne[$col['montant_liquide']] = $montant;
                    }
                    if (($col['version'] ?? null) !== null) {
                        $ligne[$col['version']] = 1;
                    }
                    $lignes[] = $ligne;
                }
            }
        }
    }

    /** @param array<string, float> $valeurs */
    private function ligneMontants(array $col, string $nif, int $annee, array $valeurs): array
    {
        $ligne = [$col['nif'] => $nif, $col['annee'] => $annee];

        foreach ($col as $champ => $nom) {
            if ($nom === null || in_array($champ, ['nif', 'annee'], true)) {
                continue;
            }
            $ligne[$nom] = $valeurs[$champ] ?? 0;
        }

        return $ligne;
    }

    private function ligneDau(array $col, string $nif, int $annee, string $modele, float $tva, float $caf): array
    {
        return [
            $col['nif'] => $nif,
            $col['annee'] => $annee,
            $col['modele'] => $modele,
            $col['tva'] => $tva,
            $col['valeur_caf'] => $caf,
        ];
    }
}
