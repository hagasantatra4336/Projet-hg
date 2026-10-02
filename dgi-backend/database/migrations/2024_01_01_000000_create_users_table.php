<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('email')->unique();
            $table->string('fonction')->nullable();
            $table->string('tel')->nullable();

            // Mot de passe défini uniquement à l'étape 3 du sign up (nullable au départ)
            $table->string('password')->nullable();

            // Vérification de l'e-mail par code OTP (étape 2 du sign up)
            $table->string('otp_code', 6)->nullable();
            $table->timestamp('otp_expires_at')->nullable();   // validité : 10 minutes
            $table->timestamp('email_verified_at')->nullable(); // rempli quand l'OTP est validé

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
