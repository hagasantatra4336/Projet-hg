<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Base d'analyse risque » : les valeurs lues dans les déclarations brutes (lignes IR et TVA), le recoupement et les DAU,
 * regroupées par contribuable. Une ligne = (contribuable, source, année fiscale, mois ; mois = 0 si annuel).
 *   source IR          : ca, resultat, reduction, ir_a_payer
 *   source TVA         : ca, ca_taxable, ca_exonere, tva_deduc_*, credit_tva, ca_taxable_export   (un mois par ligne)
 *   source RECOUPEMENT : prestations_de_service ... tva_ded_imp, marche_public                     (une année par ligne)
 *   source DAU         : dau_tva_im4 (TVA des DAU modèle IM4), dau_caf_ex1 (valeur CAF des DAU modèle EX1)
 * Elle alimente les critères de risque R1, R2, R3, R5, R6, R7. Les 6 critères sont aussi ajoutés à « Règles de détection ».
 */
return new class extends Migration
{
    private const COLONNES = [
        'ca', 'resultat', 'reduction', 'ir_a_payer',
        'ca_taxable', 'ca_exonere', 'tva_deduc_bl', 'tva_deduc_bi', 'tva_deduc_il', 'tva_deduc_ii', 'tva_deduc_abl', 'tva_deduc_abi',
        'credit_tva', 'ca_taxable_export',
        'prestations_de_service', 'achats_effectues', 'achats_consommes', 'produits_locaux_vendus', 'exportations', 'importations',
        'montant_total', 'tva_ded_achat', 'tva_ded_imp', 'marche_public',
        'dau_tva_im4', 'dau_caf_ex1',
    ];

    public function up(): void
    {
        Schema::create('base_risque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribuable_id')->constrained('contribuables')->cascadeOnDelete();
            $table->string('source', 12); // IR | TVA | RECOUPEMENT | DAU
            $table->unsignedSmallInteger('annee_fiscale');
            $table->unsignedSmallInteger('mois')->default(0);
            foreach (self::COLONNES as $c) {
                $table->decimal($c, 18, 2)->nullable();
            }
            $table->timestamps();

            $table->unique(['contribuable_id', 'source', 'annee_fiscale', 'mois']);
            $table->index(['source', 'annee_fiscale']);
        });

        $now = now();
        $regles = [
            'r1' => ['Écart entre CA IR et CA TVA (critère R1)', "Le chiffre d'affaires déclaré à l'IR et celui déclaré à la TVA ne concordent pas. Score sur 10 = écart ÷ plus grand des deux × 10."],
            'r2' => ['Déficit chronique (critère R2)', "Le résultat de l'IR est négatif plusieurs exercices de suite (3 derniers exercices). Score sur 5 : 1 exercice = 1 ; 2 = 2,5 ; 3 = 5."],
            'r3' => ['Crédit de TVA permanent (critère R3)', 'Le contribuable est créditeur de TVA pendant des mois consécutifs. Score sur 5 = nombre de mois (5 mois ou plus : 5).'],
            'r5' => ['Discordance avec le recoupement (critère R5)', "Le chiffre d'affaires déclaré (IR, TVA) s'écarte du recoupement et de la TVA déductible recoupée. Score sur 5 d'après le % d'écart."],
            'r6' => ["Risques liés à l'importation (critère R6)", 'La TVA des DAU modèle IM4 ne correspond pas à la TVA déductible déclarée sur les biens importés. Score sur 10.'],
            'r7' => ["Risques liés à l'exportation (critère R7)", "La valeur CAF des DAU modèle EX1 ne correspond pas au CA taxable à l'exportation déclaré. Score sur 10."],
        ];
        foreach ($regles as $code => [$nom, $description]) {
            if (! DB::table('regles')->where('code', $code)->exists()) {
                DB::table('regles')->insert([
                    'code' => $code, 'nom' => $nom, 'description' => $description, 'actif' => true, 'parametres' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('alertes')->whereIn('regle', ['r1', 'r2', 'r3', 'r5', 'r6', 'r7'])->delete();
        DB::table('regles')->whereIn('code', ['r1', 'r2', 'r3', 'r5', 'r6', 'r7'])->delete();
        Schema::dropIfExists('base_risque');
    }
};
