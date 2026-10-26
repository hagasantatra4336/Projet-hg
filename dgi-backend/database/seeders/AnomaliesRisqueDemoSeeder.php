<?php

namespace Database\Seeders;

use App\Models\BaseRisque;
use App\Models\Contribuable;
use App\Support\Nif;
use Illuminate\Database\Seeder;

/**
 * Exemples de critères de risque R1, R2, R3, R5, R6 et R7 sur les contribuables de DeclarationDemoSeeder.
 *
 * Un contribuable par critère, avec des valeurs choisies pour déclencher une alerte rouge
 * (et une alerte orange R2 pour l'exercice précédent, pour montrer un dossier à plusieurs anomalies) :
 *   1  → R1 : CA IR (100 M) très supérieur au CA TVA (30 M)
 *   6  → R2 : résultat déficitaire sur les trois derniers exercices
 *   7  → R3 : crédit de TVA sur 6 mois consécutifs
 *   13 → R5 : recoupement (40 M) très inférieur au CA déclaré (100 M)
 *   19 → R6 : TVA des DAU IM4 (40 M) sans TVA déductible sur biens importés (2 M)
 *   25 → R7 : valeur CAF des DAU EX1 (100 M) très supérieure au CA taxable export (30 M)
 *
 * Les lignes existantes de ces six contribuables sont remplacées (les autres contribuables ne sont pas touchés).
 * Après le chargement : php artisan dgi:detecter-defaillances
 */
class AnomaliesRisqueDemoSeeder extends Seeder
{
    public function run(): void
    {
        $annee = (int) now()->format('Y');
        $nb = 0;

        foreach ($this->exemples($annee) as $numero => $lignes) {
            $nif = Nif::normaliser('90000' . sprintf('%05d', $numero));
            $contribuable = Contribuable::where('nif', $nif)->first();

            if ($contribuable === null) {
                continue; // jeu de démonstration absent : rien à enrichir
            }

            BaseRisque::where('contribuable_id', $contribuable->id)->delete();

            foreach ($lignes as $ligne) {
                BaseRisque::create([
                    'contribuable_id' => $contribuable->id,
                    'source' => $ligne['source'],
                    'annee_fiscale' => $ligne['annee'],
                    'mois' => $ligne['mois'] ?? 0,
                    ...$ligne['valeurs'],
                ]);
            }

            $nb++;
            $this->command?->line("  {$contribuable->nom} ({$nif}) : " . count($lignes) . ' ligne(s) — ' . $lignes[0]['critere']);
        }

        if ($nb === 0) {
            $this->command?->warn("Aucun contribuable de démonstration trouvé : lancez d'abord DeclarationDemoSeeder.");

            return;
        }

        $this->command?->info("{$nb} contribuable(s) avec des exemples R1 à R7 chargés. Lancez l'analyse pour créer les alertes.");
    }

    /**
     * @return array<int, array<int, array{source: string, annee: int, mois?: int, valeurs: array<string, int|float>, critere: string}>>
     */
    private function exemples(int $annee): array
    {
        $r2 = [];
        for ($a = $annee - 2; $a <= $annee; $a++) {
            $r2[] = [
                'source' => BaseRisque::IR, 'annee' => $a, 'valeurs' => [
                    'ca' => 80_000_000, 'resultat' => -5_000_000, 'ir_a_payer' => 0,
                ], 'critere' => 'R2 · déficit sur 3 exercices',
            ];
        }

        $r3 = [];
        for ($m = 1; $m <= 6; $m++) {
            $r3[] = [
                'source' => BaseRisque::TVA, 'annee' => $annee, 'mois' => $m, 'valeurs' => [
                    'ca' => 20_000_000, 'credit_tva' => 1_000_000,
                ], 'critere' => 'R3 · crédit de TVA 6 mois',
            ];
        }

        return [
            1 => [[
                'source' => BaseRisque::IR, 'annee' => $annee, 'valeurs' => [
                    'ca' => 100_000_000, 'resultat' => 15_000_000, 'ir_a_payer' => 3_000_000,
                ], 'critere' => 'R1 · CA IR 100 M contre CA TVA 30 M',
            ], [
                'source' => BaseRisque::TVA, 'annee' => $annee, 'mois' => 1, 'valeurs' => [
                    'ca' => 30_000_000, 'ca_taxable' => 30_000_000,
                ], 'critere' => 'R1 · CA IR 100 M contre CA TVA 30 M',
            ]],

            6 => $r2,

            7 => $r3,

            13 => [[
                'source' => BaseRisque::IR, 'annee' => $annee, 'valeurs' => [
                    'ca' => 100_000_000, 'resultat' => 20_000_000, 'ir_a_payer' => 4_000_000,
                ], 'critere' => 'R5 · recoupement 40 M contre CA déclaré 100 M',
            ], [
                'source' => BaseRisque::TVA, 'annee' => $annee, 'mois' => 1, 'valeurs' => [
                    'ca' => 100_000_000, 'ca_taxable' => 100_000_000,
                ], 'critere' => 'R5 · recoupement 40 M contre CA déclaré 100 M',
            ], [
                'source' => BaseRisque::RECOUPEMENT, 'annee' => $annee, 'valeurs' => [
                    'prestations_de_service' => 40_000_000, 'montant_total' => 40_000_000,
                ], 'critere' => 'R5 · recoupement 40 M contre CA déclaré 100 M',
            ]],

            19 => [[
                'source' => BaseRisque::TVA, 'annee' => $annee, 'mois' => 1, 'valeurs' => [
                    'ca' => 100_000_000, 'tva_deduc_bi' => 2_000_000,
                ], 'critere' => 'R6 · DAU IM4 40 M contre TVA déductible 2 M',
            ], [
                'source' => BaseRisque::DAU, 'annee' => $annee, 'valeurs' => [
                    'dau_tva_im4' => 40_000_000,
                ], 'critere' => 'R6 · DAU IM4 40 M contre TVA déductible 2 M',
            ]],

            25 => [[
                'source' => BaseRisque::TVA, 'annee' => $annee, 'mois' => 1, 'valeurs' => [
                    'ca' => 30_000_000, 'ca_taxable_export' => 30_000_000,
                ], 'critere' => 'R7 · DAU EX1 100 M contre CA export 30 M',
            ], [
                'source' => BaseRisque::DAU, 'annee' => $annee, 'valeurs' => [
                    'dau_caf_ex1' => 100_000_000,
                ], 'critere' => 'R7 · DAU EX1 100 M contre CA export 30 M',
            ]],
        ];
    }
}
