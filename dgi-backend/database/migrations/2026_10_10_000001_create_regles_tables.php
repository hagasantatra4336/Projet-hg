<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Règles de détection réglables depuis l'application.
 * Une ligne par règle. « parametres » ne contient que les réglages MODIFIÉS (null = valeurs par défaut de config/anomalies.php).
 * « regles_historique » garde chaque modification (qui, quand, avant, après).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();     // defaillance | baisse_ca
            $table->string('nom');
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->json('parametres')->nullable();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('regles_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regle_id')->constrained('regles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30); // modification | reinitialisation
            $table->json('avant');
            $table->json('apres');
            $table->timestamps();
        });

        $now = now();
        DB::table('regles')->insert([
            [
                'code' => 'defaillance',
                'nom' => 'Défaut de dépôt de déclaration (critère R4)',
                'description' => "Un contribuable assujetti n'a pas déposé une déclaration dont la date limite est dépassée. "
                    . "Le score sur 5 donne le niveau de risque : déclaration annuelle non déposée, ou nombre de mois non déposés dans l'exercice.",
                'actif' => true,
                'parametres' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'baisse_ca',
                'nom' => "Baisse du chiffre d'affaires",
                'description' => "Le chiffre d'affaires déclaré baisse d'au moins le seuil indiqué : TVA par rapport au même mois de l'année précédente, "
                    . "IR par rapport à l'exercice précédent.",
                'actif' => true,
                'parametres' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('regles_historique');
        Schema::dropIfExists('regles');
    }
};
