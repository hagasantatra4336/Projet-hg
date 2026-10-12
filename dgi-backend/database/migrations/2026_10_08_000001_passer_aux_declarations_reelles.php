<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adapte la base aux déclarations RÉELLES de la DGI :
 *   - déclarations mensuelles (TVA, IRSA) et annuelles (IR, IS) : année fiscale + mois (0 = annuelle), date limite ;
 *   - obligations (assujettissements) : impôt, année, périodicité, actif, exonéré ;
 *   - contribuables : état (actif, bloqué, cessation, radié) et dates ;
 *   - centres : code officiel (ex. « 21-1-702 ») et abréviation ;
 *   - alertes : année fiscale + mois au lieu d'une période « AAAA-MM ».
 *
 * ATTENTION : les déclarations et alertes déjà enregistrées (jeu de test précédent) sont SUPPRIMÉES,
 * car elles ne sont pas convertibles. Les contribuables, centres et utilisateurs sont conservés.
 * Rechargez ensuite les données : db:seed --class=DeclarationDemoSeeder, import CSV ou import depuis la base source.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('alertes')->delete();
        DB::table('declarations')->delete();

        // Centres : référentiel officiel
        Schema::table('centres', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('nom');
            $table->string('abreviation')->nullable()->after('code');
        });

        // Contribuables : état et dates de fin d'activité (les contribuables en cessation ou radiés n'ont plus d'obligation)
        Schema::table('contribuables', function (Blueprint $table) {
            $table->unsignedSmallInteger('etat')->nullable()->after('centre_id'); // 1 actif, 2 bloqué, 3 cessation, 4 radié
            $table->date('date_cessation')->nullable()->after('etat');
            $table->date('date_radiation')->nullable()->after('date_cessation');
            $table->date('date_blocage')->nullable()->after('date_radiation');
        });

        // Déclarations : une ligne = une déclaration (contribuable, impôt, année fiscale, mois ; mois = 0 pour une déclaration annuelle)
        Schema::table('declarations', function (Blueprint $table) {
            $table->dropUnique(['contribuable_id', 'type_impot', 'periode']);
            $table->dropIndex(['type_impot', 'periode']);
            $table->dropColumn(['periode', 'montant']);
        });

        Schema::table('declarations', function (Blueprint $table) {
            $table->unsignedSmallInteger('annee_fiscale')->after('type_impot');
            $table->unsignedSmallInteger('mois')->default(0)->after('annee_fiscale');
            $table->date('date_limite')->nullable()->after('chiffre_affaires'); // date limite (DATE_ECHEANCE)

            $table->unique(['contribuable_id', 'type_impot', 'annee_fiscale', 'mois']);
            $table->index(['type_impot', 'annee_fiscale', 'mois']);
        });

        // Obligations déclaratives (assujettissements)
        Schema::create('assujettissements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribuable_id')->constrained('contribuables')->cascadeOnDelete();
            $table->string('type_impot', 10);
            $table->unsignedSmallInteger('annee_fiscale');
            $table->unsignedSmallInteger('periodicite'); // 1 mensuel, 2 bimestriel, 3 trimestriel, 4 semestriel, 5 annuel
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->boolean('actif')->default(true);
            $table->boolean('exonere')->default(false);
            $table->timestamps();

            $table->unique(['contribuable_id', 'type_impot', 'annee_fiscale']);
        });

        // Alertes : année fiscale + mois (0 = exercice entier)
        Schema::table('alertes', function (Blueprint $table) {
            $table->dropUnique(['contribuable_id', 'regle', 'type_impot', 'periode']);
            $table->dropColumn('periode');
            $table->renameColumn('mois_manques', 'periodes_manquees');   // périodes (mois ou années) non déposées
            $table->renameColumn('serie_precedente', 'periodes_declarees'); // périodes déposées dans l'exercice
        });

        Schema::table('alertes', function (Blueprint $table) {
            $table->unsignedSmallInteger('annee_fiscale')->after('type_impot');
            $table->unsignedSmallInteger('mois')->default(0)->after('annee_fiscale');
            $table->date('date_limite')->nullable()->after('mois');

            $table->unique(['contribuable_id', 'regle', 'type_impot', 'annee_fiscale', 'mois']);
        });
    }

    public function down(): void
    {
        DB::table('alertes')->delete();
        DB::table('declarations')->delete();

        Schema::table('alertes', function (Blueprint $table) {
            $table->dropUnique(['contribuable_id', 'regle', 'type_impot', 'annee_fiscale', 'mois']);
            $table->dropColumn(['annee_fiscale', 'mois', 'date_limite']);
            $table->renameColumn('periodes_manquees', 'mois_manques');
            $table->renameColumn('periodes_declarees', 'serie_precedente');
        });

        Schema::table('alertes', function (Blueprint $table) {
            $table->string('periode', 7)->after('type_impot');
            $table->unique(['contribuable_id', 'regle', 'type_impot', 'periode']);
        });

        Schema::dropIfExists('assujettissements');

        Schema::table('declarations', function (Blueprint $table) {
            $table->dropUnique(['contribuable_id', 'type_impot', 'annee_fiscale', 'mois']);
            $table->dropIndex(['type_impot', 'annee_fiscale', 'mois']);
            $table->dropColumn(['annee_fiscale', 'mois', 'date_limite']);
        });

        Schema::table('declarations', function (Blueprint $table) {
            $table->string('periode', 7)->after('type_impot');
            $table->decimal('montant', 16, 2)->default(0)->after('periode');
            $table->unique(['contribuable_id', 'type_impot', 'periode']);
            $table->index(['type_impot', 'periode']);
        });

        Schema::table('contribuables', function (Blueprint $table) {
            $table->dropColumn(['etat', 'date_cessation', 'date_radiation', 'date_blocage']);
        });

        Schema::table('centres', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'abreviation']);
        });
    }
};
