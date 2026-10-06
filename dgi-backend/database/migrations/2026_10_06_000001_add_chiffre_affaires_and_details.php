<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chiffre d'affaires déclaré (facultatif) : sert à la règle « baisse du chiffre d'affaires »
        Schema::table('declarations', function (Blueprint $table) {
            $table->decimal('chiffre_affaires', 16, 2)->nullable()->after('montant');
        });

        // Détails propres à chaque règle (CA précédent, CA actuel, % de baisse...)
        Schema::table('alertes', function (Blueprint $table) {
            $table->json('details')->nullable()->after('motif');
        });
    }

    public function down(): void
    {
        Schema::table('alertes', function (Blueprint $table) {
            $table->dropColumn('details');
        });

        Schema::table('declarations', function (Blueprint $table) {
            $table->dropColumn('chiffre_affaires');
        });
    }
};
