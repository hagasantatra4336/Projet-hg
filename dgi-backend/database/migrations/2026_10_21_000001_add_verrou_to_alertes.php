<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traitement d'un dossier : un agent qui clique sur « Traiter » PREND le dossier (statut affiché « En traitement »).
 * Les autres utilisateurs ne peuvent plus le prendre tant qu'il est pris. Le verrou expire de lui-même (voir VerrouAlerte).
 * « statut » ne change pas : le verrou est porté par ces 2 colonnes, donc les règles de détection restent inchangées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alertes', function (Blueprint $table) {
            $table->foreignId('pris_par')->nullable()->after('traite_le')->constrained('users')->nullOnDelete();
            $table->timestamp('pris_le')->nullable()->after('pris_par');
        });
    }

    public function down(): void
    {
        Schema::table('alertes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pris_par');
            $table->dropColumn('pris_le');
        });
    }
};
