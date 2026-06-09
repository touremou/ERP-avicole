<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            // Ajout de la colonne manquante avec une valeur par défaut à 'true'
            $table->boolean('is_active')->default(true)->after('target_type');
            
        });
    }

    public function down(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
