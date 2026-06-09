<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            // On ajoute les colonnes qui manquaient selon votre erreur SQL
            // On les place après 'product_name' pour une structure propre
            $table->string('batch_number')->nullable()->after('product_name');
            $table->date('expiry_date')->nullable()->after('batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->dropColumn(['batch_number', 'expiry_date']);
        });
    }
};