<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centres', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('adresse')->nullable();
            $table->timestamps();
        });

        // Chaque fonction porte un rôle : agent | admin | central | superadmin
        Schema::create('fonctions', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('role', 20)->default('agent');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fonctions');
        Schema::dropIfExists('centres');
    }
};
