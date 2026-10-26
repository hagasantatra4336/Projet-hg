<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'inscription des agents : elles vivent en base (et non en cache) car un chef de centre
 * doit pouvoir les consulter puis les approuver plusieurs heures après leur dépôt.
 *
 * Cycle de vie :
 *   en_attente → approuvee (code OTP envoyé, valable 5 min) → compte_cree
 *              → refusee
 *              → annulee (par le demandeur)
 *              → expiree (7 jours sans décision)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_inscription', function (Blueprint $table) {
            $table->id();

            // Secret public de la demande (48 caractères aléatoires) : la page d'attente s'en sert pour suivre l'état.
            $table->string('request_id', 48)->unique();

            $table->string('nom');
            $table->string('email');
            $table->string('im', 50)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('adresse')->nullable();

            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('fonction_id')->constrained('fonctions')->restrictOnDelete();

            $table->string('statut', 20)->default('en_attente');
            // Niveau qui doit approuver : chef (admin du centre), central ou superadmin (si aucun chef).
            $table->string('niveau_approbation', 20)->nullable();

            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();
            $table->text('motif_refus')->nullable();

            // OTP d'inscription : empreinte HMAC seulement, jamais le code en clair.
            $table->string('otp_hash', 64)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->unsignedTinyInteger('otp_renvois')->default(0);
            $table->timestamp('otp_envoye_le')->nullable();

            // Jeton temporaire remis après vérification du code, exigé pour définir le mot de passe.
            $table->string('setup_hash', 64)->nullable();
            $table->timestamp('setup_expires_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['email', 'statut']);
            $table->index(['centre_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_inscription');
    }
};
