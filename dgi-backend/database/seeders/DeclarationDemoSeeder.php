<?php

namespace Database\Seeders;

use App\Models\Assujettissement;
use App\Models\Centre;
use App\Models\Contribuable;
use App\Models\Declaration;
use App\Support\Nif;
use DateTimeImmutable;
use Illuminate\Database\Seeder;

/**
 * Jeu de données de test SIMULÉ ET ANONYMISÉ (aucune donnée réelle) pour démontrer la détection :
 *   php artisan db:seed --class=DeclarationDemoSeeder
 * Il faut au moins un centre. Relançable sans doublons. Ensuite, lancez l'analyse depuis la page
 * « Anomalies » (ou : php artisan dgi:detecter-defaillances).
 *
 * 36 contribuables répartis sur les centres, 12 scénarios (3 contribuables chacun) :
 *   0 TVA régulière | 1 TVA régulière avec baisse du CA en août 2026 (35 %, 55 %, 75 % par rapport à août 2025)
 *   2 TVA : juin à août 2026 non déposés (3 mois) | 3 TVA : avril à août 2026 non déposés (5 mois)
 *   4 TVA : mars à décembre 2025 non déposés (10 mois) puis tout 2026 (8 mois) | 5 IR + TVA réguliers
 *   6 IR 2025 non déposé | 7 IR avec baisse du CA 2025 (55 %, 40 %, 75 %)
 *   8 cessation (plus d'obligation après) | 9 radié (plus d'obligation) | 10 TVA exonérée sans déclaration
 *   11 sans assujettissement connu : obligation déduite de l'historique (TVA, mars à août 2026 non déposés)
 * Les dates limites sont celles de la DGI : TVA le 15 du mois suivant, IR le 31 mai de l'année suivante.
 */
class DeclarationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $centres = Centre::orderBy('id')->pluck('id')->all();

        if ($centres === []) {
            $this->command?->error("Créez d'abord au moins un centre (Administration > Centres).");

            return;
        }

        $jeu = self::jeu('90000', 'Contribuable anonyme ', new DateTimeImmutable(now()->toDateString()));
        $now = now()->toDateTimeString();

        $ids = [];
        foreach ($jeu['contribuables'] as $i => $c) {
            $contribuable = Contribuable::updateOrCreate(['nif' => $c['nif']], [
                'nom' => $c['nom'],
                'centre_id' => $centres[($i + intdiv($i, 12)) % count($centres)], // mélangé : $i % n mettrait tous les scénarios identiques dans le même centre
                'etat' => $c['etat'],
                'date_cessation' => $c['date_cessation'],
                'date_radiation' => $c['date_radiation'],
                'date_blocage' => $c['date_blocage'],
            ]);
            $ids[$c['nif']] = $contribuable->id;
        }

        foreach (array_chunk($jeu['assujettissements'], 500) as $lot) {
            Assujettissement::upsert(
                array_map(fn ($a) => [
                    'contribuable_id' => $ids[$a['nif']],
                    'type_impot' => $a['type_impot'],
                    'annee_fiscale' => $a['annee_fiscale'],
                    'periodicite' => $a['periodicite'],
                    'date_debut' => $a['date_debut'],
                    'date_fin' => $a['date_fin'],
                    'actif' => $a['actif'],
                    'exonere' => $a['exonere'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $lot),
                ['contribuable_id', 'type_impot', 'annee_fiscale'],
                ['periodicite', 'date_debut', 'date_fin', 'actif', 'exonere', 'updated_at']
            );
        }

        foreach (array_chunk($jeu['declarations'], 500) as $lot) {
            Declaration::upsert(
                array_map(fn ($d) => [
                    'contribuable_id' => $ids[$d['nif']],
                    'type_impot' => $d['type_impot'],
                    'annee_fiscale' => $d['annee_fiscale'],
                    'mois' => $d['mois'],
                    'chiffre_affaires' => $d['chiffre_affaires'],
                    'date_limite' => $d['date_limite'],
                    'date_depot' => $d['date_depot'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $lot),
                ['contribuable_id', 'type_impot', 'annee_fiscale', 'mois'],
                ['chiffre_affaires', 'date_limite', 'date_depot', 'updated_at']
            );
        }

        $this->command?->info(count($jeu['contribuables']) . ' contribuables, ' . count($jeu['assujettissements']) . ' assujettissements et '
            . count($jeu['declarations']) . " déclarations de test créés. Lancez maintenant l'analyse.");
    }

    /**
     * Génère le jeu de test (partagé avec la commande dgi:source-test-db). Fonction pure : aucun accès base.
     *
     * @return array{contribuables: array<int, array<string, mixed>>, assujettissements: array<int, array<string, mixed>>, declarations: array<int, array<string, mixed>>}
     */
    public static function jeu(string $prefixeNif, string $prefixeNom, DateTimeImmutable $aujourdhui): array
    {
        mt_srand(42); // résultats reproductibles

        $annee = (int) $aujourdhui->format('Y');

        // Dernier mois de TVA dont la date limite (15 du mois suivant) est dépassée
        $anneeDu = $annee;
        $moisDu = (int) $aujourdhui->format('n') - 1; // le mois précédent est dû à partir du 16
        if ((int) $aujourdhui->format('j') <= 15) {
            $moisDu--;
        }
        while ($moisDu < 1) {
            $moisDu += 12;
            $anneeDu--;
        }

        $anneeIr = $annee - 1; // dernier exercice IR dont la date limite (31 mai de l'année suivante) est dépassée

        $baissesTva = [2 => 35, 14 => 55, 26 => 75]; // scénario 1 : baisse du CA du dernier mois dû, par n° de contribuable
        $baissesIr = [8 => 55, 20 => 40, 32 => 75];  // scénario 7 : baisse du CA IR du dernier exercice

        $contribuables = [];
        $assujettissements = [];
        $declarations = [];

        $limiteMensuelle = fn (int $a, int $m) => sprintf('%04d-%02d-15', $m === 12 ? $a + 1 : $a, $m === 12 ? 1 : $m + 1);
        $limiteAnnuelle = fn (int $a) => sprintf('%04d-05-31', $a + 1);
        $avant = fn (string $date, int $jours) => (new DateTimeImmutable($date))->modify('-' . $jours . ' days')->format('Y-m-d');

        for ($i = 1; $i <= 36; $i++) {
            $scenario = ($i - 1) % 12;
            $nif = Nif::normaliser($prefixeNif . sprintf('%05d', $i));
            $base = mt_rand(20_000_000, 300_000_000); // CA mensuel moyen

            $c = [
                'nif' => $nif, 'nom' => sprintf('%s%03d', $prefixeNom, $i), 'etat' => 1,
                'date_cessation' => null, 'date_radiation' => null, 'date_blocage' => null,
            ];

            // ---- TVA : mois déposés, de janvier (année - 2) jusqu'au dernier mois déposé ----
            $tva = null; // [dernier an, dernier mois]
            switch ($scenario) {
                case 0: case 1: case 5: case 6: $tva = [$anneeDu, $moisDu]; break;
                case 2: $tva = self::reculer($anneeDu, $moisDu, 3); break;   // les 3 derniers mois dus ne sont pas déposés
                case 3: $tva = self::reculer($anneeDu, $moisDu, 5); break;   // les 5 derniers
                case 4: $tva = [$annee - 1, 2]; break;                       // plus rien depuis mars de l'année précédente
                case 8: $tva = [$annee - 1, 9]; break;                       // cessation le 30/09 de l'année précédente
                case 11: $tva = self::reculer($anneeDu, $moisDu, 6); break;  // sans assujettissement : les 6 derniers mois non déposés
            }

            if ($tva !== null) {
                $lignes = [];
                for ($a = $annee - 2; $a <= $tva[0]; $a++) {
                    for ($m = 1; $m <= 12; $m++) {
                        if ($a === $tva[0] && $m > $tva[1]) {
                            break;
                        }
                        $lignes[$a . '-' . $m] = round($base * mt_rand(92, 108) / 100, 2); // stable à ± 8 %
                    }
                }
                if ($scenario === 1) {
                    // baisse du dernier mois dû par rapport au même mois de l'année précédente
                    $lignes[$anneeDu . '-' . $moisDu] = round($lignes[($anneeDu - 1) . '-' . $moisDu] * (1 - $baissesTva[$i] / 100), 2);
                }
                foreach ($lignes as $cle => $valeur) {
                    [$a, $m] = array_map('intval', explode('-', $cle));
                    $limite = $limiteMensuelle($a, $m);
                    $declarations[] = [
                        'nif' => $nif, 'type_impot' => 'TVA', 'annee_fiscale' => $a, 'mois' => $m, 'chiffre_affaires' => $valeur,
                        'date_limite' => $limite, 'date_depot' => $avant($limite, mt_rand(1, 9)),
                    ];
                }
            }

            // ---- IR : exercices déposés ----
            $exercices = [];
            if ($scenario === 5 || $scenario === 7) {
                $exercices = [$anneeIr - 2, $anneeIr - 1, $anneeIr];
            } elseif ($scenario === 6) {
                $exercices = [$anneeIr - 2, $anneeIr - 1]; // l'exercice $anneeIr n'est pas déposé
            } elseif ($scenario === 9) {
                $exercices = [$annee - 4, $annee - 3];
            }
            foreach ($exercices as $a) {
                $valeur = round($base * 12 * mt_rand(95, 105) / 100, 2);
                if ($scenario === 7 && $a === $anneeIr - 1) {
                    $valeur = round($base * 12, 2);
                }
                if ($scenario === 7 && $a === $anneeIr) {
                    $valeur = round($base * 12 * (1 - $baissesIr[$i] / 100), 2);
                }
                $limite = $limiteAnnuelle($a);
                $declarations[] = [
                    'nif' => $nif, 'type_impot' => 'IR', 'annee_fiscale' => $a, 'mois' => 0, 'chiffre_affaires' => $valeur,
                    'date_limite' => $limite, 'date_depot' => $avant($limite, mt_rand(1, 15)),
                ];
            }

            // ---- État du contribuable ----
            if ($scenario === 8) {
                $c['etat'] = 3;
                $c['date_cessation'] = sprintf('%04d-09-30', $annee - 1);
            } elseif ($scenario === 9) {
                $c['etat'] = 4;
                $c['date_radiation'] = sprintf('%04d-06-30', $annee - 2);
            } elseif ($scenario === 10) {
                $c['etat'] = 2;
                $c['date_blocage'] = sprintf('%04d-02-01', $annee);
            }

            // ---- Assujettissements (sauf scénario 11 : aucun) ----
            if ($scenario !== 11) {
                for ($a = $annee - 2; $a <= $annee; $a++) {
                    $tvaAssujetti = in_array($scenario, [0, 1, 2, 3, 4, 5, 6, 8, 10], true);
                    $irAssujetti = in_array($scenario, [5, 6, 7, 9], true);
                    foreach ([['TVA', 1, $tvaAssujetti, $scenario === 10], ['IR', 5, $irAssujetti, false]] as [$impot, $periodicite, $assujetti, $exonere]) {
                        if ($assujetti) {
                            $assujettissements[] = [
                                'nif' => $nif, 'type_impot' => $impot, 'annee_fiscale' => $a, 'periodicite' => $periodicite,
                                'date_debut' => sprintf('%04d-01-01', $a), 'date_fin' => sprintf('%04d-12-31', $a),
                                'actif' => true, 'exonere' => $exonere,
                            ];
                        }
                    }
                }
            }

            $contribuables[] = $c;
        }

        return ['contribuables' => $contribuables, 'assujettissements' => $assujettissements, 'declarations' => $declarations];
    }

    /** Recule de $n mois à partir de (année, mois) et retourne [année, mois]. */
    private static function reculer(int $annee, int $mois, int $n): array
    {
        $mois -= $n;
        while ($mois < 1) {
            $mois += 12;
            $annee--;
        }

        return [$annee, $mois];
    }
}