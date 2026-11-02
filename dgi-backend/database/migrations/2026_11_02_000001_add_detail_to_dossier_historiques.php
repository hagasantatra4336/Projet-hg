<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Détail libre d'une action du journal : par exemple le nom de l'agent à qui le dossier est assigné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_historiques', function (Blueprint $table) {
            $table->string('detail', 160)->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_historiques', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }
};
