<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le code OTP d'approbation passe de 5 minutes à 24 heures : un rappel est envoyé une heure
 * avant l'expiration. Cette colonne mémorise le rappel déjà parti, pour ne l'envoyer qu'une fois
 * par code (elle est remise à null à chaque nouvel envoi de code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_inscription', function (Blueprint $table) {
            $table->timestamp('rappel_otp_envoye_le')->nullable()->after('otp_envoye_le');

            // Le planificateur interroge les codes proches de l'expiration : cet index évite
            // un parcours complet de la table à chaque passage.
            $table->index(['statut', 'otp_expires_at'], 'demandes_otp_expiration_index');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_inscription', function (Blueprint $table) {
            $table->dropIndex('demandes_otp_expiration_index');
            $table->dropColumn('rappel_otp_envoye_le');
        });
    }
};
