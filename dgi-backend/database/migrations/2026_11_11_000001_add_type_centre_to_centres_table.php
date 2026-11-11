<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            // 'central' : service central, dont l'affichage est contrôlé par le super admin ;
            // '-' : centre fiscal, toujours proposé.
            $table->string('type_centre', 20)->default('-')->after('abreviation');
            $table->boolean('affiche')->default(true)->after('type_centre');
        });

        // Service central SSIF : masqué ou affiché depuis la page Centres (super admin uniquement).
        DB::table('centres')->updateOrInsert(
            ['nom' => 'SSIF'],
            [
                'adresse' => 'Mandrosoa',
                'abreviation' => 'SSIF',
                'type_centre' => 'central',
                'affiche' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // La fiche SSIF est conservée : elle peut déjà être rattachée à des utilisateurs.
        Schema::table('centres', function (Blueprint $table) {
            $table->dropColumn(['type_centre', 'affiche']);
        });
    }
};
