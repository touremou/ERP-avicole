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
    Schema::table('feed_purchases', function (Blueprint $table) {
        // On ajoute la colonne unit après 'quantity'
        $table->string('unit')->default('KG')->after('quantity'); 
    });
}

public function down(): void
{
    Schema::table('feed_purchases', function (Blueprint $table) {
        $table->dropColumn('unit');
    });
}
};
