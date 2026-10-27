<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordonnées du contribuable, saisies dans l'application : la base source ne les fournit pas
 * (info_contribuable ne contient que NIF, raison sociale, centre, état et dates).
 * L'import de la source ne touche jamais à ces colonnes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribuables', function (Blueprint $table) {
            $table->string('telephone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('adresse', 255)->nullable();
            $table->string('activite', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contribuables', function (Blueprint $table) {
            $table->dropColumn(['telephone', 'email', 'adresse', 'activite']);
        });
    }
};
