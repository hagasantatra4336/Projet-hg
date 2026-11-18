<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des activités du système : connexions, déconnexions et actions qui modifient les données.
 * Le nom, le rôle et l'appareil sont recopiés pour rester lisibles si le compte est renommé ou supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nom', 120);
            $table->string('role', 20)->nullable();
            $table->string('categorie', 30);       // connexion | administration | metier | regles | profil
            $table->string('action', 60);          // clé technique (connexion, creation_utilisateur...)
            $table->string('description', 255);    // phrase lisible affichée dans la liste
            $table->string('cible', 160)->nullable();
            $table->string('methode', 10)->nullable();
            $table->string('chemin', 191)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['categorie', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activites');
    }
};
