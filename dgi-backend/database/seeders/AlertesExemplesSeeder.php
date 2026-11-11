<?php

namespace Database\Seeders;

use App\Models\Alerte;
use App\Models\Contribuable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Remplace TOUTES les alertes par un exemple par règle de détection (R4, baisse du CA, R1, R2, R3, R5, R6, R7).
 * Chaque exemple porte sur un contribuable différent et un exercice différent, pour visualiser tous les critères
 * dans la liste, le filtre « Exercice » et les fiches.
 *
 * ATTENTION : le journal des dossiers (dossier_historiques) est aussi effacé, car il se rattachait aux alertes supprimées.
 * Contribuables, déclarations et base d'analyse risque ne sont pas modifiés.
 */
class AlertesExemplesSeeder extends Seeder
{
    public function run(): void
    {
        $contribuables = Contribuable::query()
            ->where('etat', 1)
            ->orderBy('id')
            ->limit(8)
            ->get();

        if ($contribuables->count() < 8) {
            $this->command?->error('Il faut au moins 8 contribuables actifs pour créer les exemples (aucune alerte modifiée).');

            return;
        }

        DB::transaction(function () use ($contribuables) {
            DB::table('dossier_historiques')->delete();
            Alerte::query()->delete();

            foreach ($this->exemples() as $i => $exemple) {
                Alerte::forceCreate([
                    'periodes_manquees' => 0,
                    'periodes_declarees' => 0,
                    'statut' => Alerte::A_TRAITER,
                    'created_at' => now()->subDays($i * 7),
                    'updated_at' => now()->subDays($i * 7),
                ] + $exemple + ['contribuable_id' => $contribuables[$i]->id]);
            }
        });

        $this->command?->info('8 alertes d\'exemple créées : R4 (2025), baisse du CA (2026), R1 (2024), R2 (2023), R3 (2022), R5 (2021), R6 (2020), R7 (2019).');
    }

    /**
     * Une alerte par règle, avec les champs que produisent les détecteurs (motif et détails compris).
     *
     * @return array<int, array<string, mixed>>
     */
    private function exemples(): array
    {
        return [
            [
                'regle' => Alerte::REGLE_DEFAILLANCE,
                'type_impot' => 'TVA',
                'annee_fiscale' => 2025,
                'mois' => 0,
                'date_limite' => '2025-04-15',
                'periodes_manquees' => 10,
                'periodes_declarees' => 2,
                'niveau' => 'moyen',
                'motif' => "TVA : 10 mois non déposés pour l'exercice 2025 (mars à décembre) sur 12 dus. Plus ancienne date limite dépassée : 15/04/2025. Score R4 : 4/5.",
                'details' => [
                    'score_r4' => 4,
                    'periodicite' => 'mensuelle',
                    'mois_manquants' => [3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                    'jours_retard' => 575,
                    'origine_obligation' => 'assujettissement',
                ],
            ],
            [
                'regle' => Alerte::REGLE_BAISSE_CA,
                'type_impot' => 'TVA',
                'annee_fiscale' => 2026,
                'mois' => 9,
                'date_limite' => '2026-10-15',
                'niveau' => 'moyen',
                'motif' => "TVA : chiffre d'affaires de 48 000 000 Ar déclaré pour septembre 2026, en baisse de 60,0 % par rapport aux 120 000 000 Ar de septembre 2025.",
                'details' => [
                    'ca_precedent' => 120_000_000,
                    'ca_actuel' => 48_000_000,
                    'baisse_pct' => 60.0,
                    'reference' => 'meme_mois_annee_precedente',
                    'annee_precedente' => 2025,
                ],
            ],
            [
                'regle' => 'r1',
                'type_impot' => 'IR/TVA',
                'annee_fiscale' => 2024,
                'mois' => 0,
                'niveau' => 'moyen',
                'motif' => "IR et TVA : le chiffre d'affaires de 2024 diffère de 40 % entre l'IR (500 000 000 Ar) et la TVA (300 000 000 Ar). Score R1 : 4/10.",
                'details' => [
                    'score_risque' => 4.0,
                    'score_max' => 10,
                    'indicateurs' => ['ca_ir' => 500_000_000, 'ca_tva' => 300_000_000, 'ecart_pct' => 40.0],
                ],
            ],
            [
                'regle' => 'r2',
                'type_impot' => 'IR',
                'annee_fiscale' => 2023,
                'mois' => 0,
                'niveau' => 'eleve',
                'motif' => "IR : résultat déficitaire pour 3 exercices consécutifs jusqu'en 2023 (résultat -5 000 000 Ar). Score R2 : 5/5.",
                'details' => [
                    'score_risque' => 5.0,
                    'score_max' => 5,
                    'indicateurs' => ['exercices_deficitaires' => 3, 'resultat' => -5_000_000],
                ],
            ],
            [
                'regle' => 'r3',
                'type_impot' => 'TVA',
                'annee_fiscale' => 2022,
                'mois' => 0,
                'niveau' => 'eleve',
                'motif' => "TVA : crédit de TVA depuis 6 mois consécutifs, jusqu'à juin 2022 (crédit de 1 200 000 Ar). Score R3 : 5/5.",
                'details' => [
                    'score_risque' => 5.0,
                    'score_max' => 5,
                    'indicateurs' => ['mois_consecutifs' => 6, 'dernier_mois' => 6, 'credit_tva' => 1_200_000],
                ],
            ],
            [
                'regle' => 'r5',
                'type_impot' => 'IR/TVA',
                'annee_fiscale' => 2021,
                'mois' => 0,
                'niveau' => 'eleve',
                'motif' => "Le chiffre d'affaires déclaré en 2021 (IR 100 000 000 Ar, TVA 100 000 000 Ar) s'écarte du recoupement (40 000 000 Ar). Score R5 : 5/5.",
                'details' => [
                    'score_risque' => 5.0,
                    'score_max' => 5,
                    'indicateurs' => [
                        'ca_recoupement' => 40_000_000,
                        'ca_ir' => 100_000_000,
                        'ca_tva' => 100_000_000,
                        'ca_non_declare' => 0,
                        'ecart_ir_pct' => 60.0,
                        'ecart_tva_pct' => 60.0,
                    ],
                ],
            ],
            [
                'regle' => 'r6',
                'type_impot' => 'TVA',
                'annee_fiscale' => 2020,
                'mois' => 0,
                'niveau' => 'moyen',
                'motif' => "Importations 2020 : TVA des DAU IM4 de 50 000 000 Ar contre 20 000 000 Ar de TVA déductible sur biens importés (écart entre DAU et déclaration). Score R6 : 6/10.",
                'details' => [
                    'score_risque' => 6.0,
                    'score_max' => 10,
                    'indicateurs' => ['tva_dau_im4' => 50_000_000, 'tva_deduc_biens_importes' => 20_000_000],
                ],
            ],
            [
                'regle' => 'r7',
                'type_impot' => 'TVA',
                'annee_fiscale' => 2019,
                'mois' => 0,
                'niveau' => 'eleve',
                'motif' => "Exportations 2019 : valeur CAF des DAU EX1 de 100 000 000 Ar contre 30 000 000 Ar de CA taxable à l'exportation (écart entre DAU et déclaration). Score R7 : 7/10.",
                'details' => [
                    'score_risque' => 7.0,
                    'score_max' => 10,
                    'indicateurs' => ['valeur_caf_ex1' => 100_000_000, 'ca_taxable_export' => 30_000_000],
                ],
            ],
        ];
    }
}
