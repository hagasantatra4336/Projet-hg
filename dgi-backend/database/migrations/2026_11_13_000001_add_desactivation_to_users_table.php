<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('desactive_le')->nullable();
            $table->text('motif_desactivation')->nullable();
            $table->foreignId('desactive_par_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desactive_par_id');
            $table->dropColumn(['desactive_le', 'motif_desactivation']);
        });
    }
};
