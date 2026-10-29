<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des actions d'un dossier contribuable : prise, reprise, libération, clôture, réouverture.
 * Le nom de l'auteur est recopié pour rester lisible si le compte est renommé ou supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribuable_id')->constrained('contribuables')->cascadeOnDelete();
            $table->foreignId('alerte_id')->nullable()->constrained('alertes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auteur', 120);
            $table->string('action', 20);          // prise | reprise | liberation | cloture | reouverture
            $table->string('statut_avant', 20);
            $table->string('statut_apres', 20);
            $table->timestamps();

            $table->index(['contribuable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_historiques');
    }
};
