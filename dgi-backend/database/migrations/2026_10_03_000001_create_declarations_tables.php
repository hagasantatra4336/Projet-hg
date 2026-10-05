<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contribuables (données de test anonymisées ou importées en CSV)
        Schema::create('contribuables', function (Blueprint $table) {
            $table->id();
            $table->string('nif', 30)->unique(); // Numéro d'Identification Fiscale
            $table->string('nom');
            $table->foreignId('centre_id')->nullable()->constrained('centres')->nullOnDelete();
            $table->timestamps();
        });

        // Une ligne = une déclaration d'un impôt (TVA, IR, IS, IRSA) pour une période mensuelle "AAAA-MM"
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribuable_id')->constrained('contribuables')->cascadeOnDelete();
            $table->string('type_impot', 10);
            $table->string('periode', 7);
            $table->decimal('montant', 16, 2)->default(0);
            $table->date('date_depot')->nullable();
            $table->timestamps();

            $table->unique(['contribuable_id', 'type_impot', 'periode']);
            $table->index(['type_impot', 'periode']);
        });

        // Alertes générées par le moteur de règles (ici : règle "defaillance")
        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribuable_id')->constrained('contribuables')->cascadeOnDelete();
            $table->string('regle', 30)->default('defaillance');
            $table->string('type_impot', 10);
            $table->string('periode', 7);                       // première période manquante
            $table->unsignedSmallInteger('mois_manques');        // mois consécutifs sans déclaration
            $table->unsignedSmallInteger('serie_precedente');    // mois consécutifs déclarés avant la rupture
            $table->string('niveau', 10);                        // faible | moyen | eleve
            $table->text('motif');
            $table->string('statut', 20)->default('a_traiter');  // a_traiter | traitee | regularisee
            $table->text('commentaire')->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();
            $table->timestamps();

            $table->unique(['contribuable_id', 'regle', 'type_impot', 'periode']);
            $table->index(['statut', 'niveau']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes');
        Schema::dropIfExists('declarations');
        Schema::dropIfExists('contribuables');
    }
};
