<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute (seulement si elles n'existent pas déjà) les colonnes "nom", "fonction" et "tel".
 * Aucune colonne OTP : le code n'est jamais enregistré dans la table users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'nom')) {
                $table->string('nom')->nullable();
            }
            if (! Schema::hasColumn('users', 'fonction')) {
                $table->string('fonction')->nullable();
            }
            if (! Schema::hasColumn('users', 'tel')) {
                $table->string('tel', 30)->nullable();
            }
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
        });

        // La colonne "name" de Laravel n'est pas utilisée (on a "nom") : on la rend optionnelle.
        // Laravel 11+ : ->change() natif. Laravel 10 : "composer require doctrine/dbal" d'abord.
        if (Schema::hasColumn('users', 'name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Volontairement vide : on ne supprime pas de colonnes de la table users.
    }
};
