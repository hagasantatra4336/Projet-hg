<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table users : ajoute telephone, adresse, im, centre_id, fonction_id.
 *  - l'ancienne colonne "tel" est RENOMMÉE en "telephone" (données conservées) ;
 *  - l'ancienne colonne texte "fonction" est convertie : chaque valeur distincte devient une ligne de
 *    la table "fonctions" (rôle "agent" par défaut), puis la colonne texte est supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'tel') && ! Schema::hasColumn('users', 'telephone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('tel', 'telephone');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'telephone')) {
                $table->string('telephone', 30)->nullable();
            }
            if (! Schema::hasColumn('users', 'adresse')) {
                $table->string('adresse')->nullable();
            }
            if (! Schema::hasColumn('users', 'im')) {
                $table->string('im', 50)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'centre_id')) {
                $table->foreignId('centre_id')->nullable()->constrained('centres')->restrictOnDelete();
            }
            if (! Schema::hasColumn('users', 'fonction_id')) {
                $table->foreignId('fonction_id')->nullable()->constrained('fonctions')->restrictOnDelete();
            }
        });

        if (Schema::hasColumn('users', 'fonction')) {
            $anciennes = DB::table('users')
                ->whereNotNull('fonction')
                ->where('fonction', '<>', '')
                ->distinct()
                ->pluck('fonction');

            foreach ($anciennes as $nom) {
                $id = DB::table('fonctions')->where('nom', $nom)->value('id')
                    ?? DB::table('fonctions')->insertGetId([
                        'nom' => $nom,
                        'role' => 'agent',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('users')->where('fonction', $nom)->update(['fonction_id' => $id]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('fonction');
            });
        }
    }

    public function down(): void
    {
        // Retour partiel : supprime les colonnes ajoutées (l'ancienne colonne texte "fonction" n'est pas recréée).
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'centre_id')) {
                $table->dropConstrainedForeignId('centre_id');
            }
            if (Schema::hasColumn('users', 'fonction_id')) {
                $table->dropConstrainedForeignId('fonction_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['im', 'adresse']);
        });
    }
};
