<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 2 : indicateurs + règles personnalisées (brouillon → simulation → activation).
 *
 *  - « indicateurs » : catalogue des valeurs lues dans les déclarations. Un indicateur = une colonne de la table
 *    « declarations » (« colonne ») avec un libellé, une unité et, si besoin, les impôts concernés.
 *  - « regles » (étendue) : les 2 règles existantes restent « systeme » ; les règles créées dans l'application sont
 *    « personnalisee » (modèle de départ, conditions combinables ET/OU, impôts, niveau, statut, empreinte).
 *  - « regles_simulations » : résultat de chaque simulation (aucune alerte créée), lié à l'empreinte de la règle simulée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicateurs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();     // identifiant utilisé dans les conditions (ex. chiffre_affaires)
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->string('colonne', 63);            // colonne de la table « declarations »
            $table->string('unite', 20)->nullable();  // Ar, %, nombre...
            $table->json('impots')->nullable();       // null = tous les impôts
            $table->boolean('actif')->default(true);
            $table->boolean('systeme')->default(false); // fourni avec l'application : non supprimable
            $table->timestamps();
        });

        Schema::table('regles', function (Blueprint $table) {
            $table->string('type', 20)->default('systeme')->after('code');     // systeme | personnalisee
            $table->string('modele', 40)->nullable()->after('type');            // modèle de départ (règles personnalisées)
            $table->string('statut', 20)->default('active')->after('modele');   // brouillon | active | desactivee
            $table->json('conditions')->nullable()->after('parametres');        // arbre ET/OU (règles personnalisées)
            $table->json('impots')->nullable()->after('conditions');            // impôts contrôlés (règles personnalisées)
            $table->string('niveau', 10)->nullable()->after('impots');          // faible | moyen | eleve
            $table->string('empreinte', 40)->nullable()->after('niveau');       // sha1 de la définition : lie simulation et activation
            $table->unsignedInteger('version')->default(1)->after('empreinte');
            $table->foreignId('cree_par')->nullable()->after('modifie_par')->constrained('users')->nullOnDelete();
            $table->index(['type', 'statut']);
        });

        Schema::create('regles_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regle_id')->constrained('regles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('empreinte', 40);          // définition simulée : une modification de la règle invalide la simulation
            $table->unsignedInteger('version');
            $table->date('date_analyse');
            $table->foreignId('centre_id')->nullable()->constrained('centres')->nullOnDelete(); // null = tous les centres
            $table->string('statut', 20);             // reussie | erreur
            $table->string('erreur', 500)->nullable();
            $table->json('resultats')->nullable();    // volumes, répartitions, échantillon d'alertes simulées
            $table->timestamps();
            $table->index(['regle_id', 'empreinte']);
        });

        DB::table('indicateurs')->insert([
            [
                'code' => 'chiffre_affaires',
                'libelle' => "Chiffre d'affaires",
                'description' => "Chiffre d'affaires déclaré (ligne 15 de l'IR, ligne 170 de la TVA).",
                'colonne' => 'chiffre_affaires',
                'unite' => 'Ar',
                'impots' => null,
                'actif' => true,
                'systeme' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('regles_simulations');

        DB::table('regles')->where('type', 'personnalisee')->delete();

        Schema::table('regles', function (Blueprint $table) {
            $table->dropIndex(['type', 'statut']);
            $table->dropConstrainedForeignId('cree_par');
            $table->dropColumn(['type', 'modele', 'statut', 'conditions', 'impots', 'niveau', 'empreinte', 'version']);
        });

        Schema::dropIfExists('indicateurs');
    }
};
