<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des observations d'un contribuable : chaque note est horodatée et signée par son auteur,
 * et reprise dans la fiche de résultat (écran et PDF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiche_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribuable_id')->constrained('contribuables')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('texte');
            $table->timestamps();

            $table->index(['contribuable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_observations');
    }
};
