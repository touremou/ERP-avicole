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
        Schema::table('buildings', function (Blueprint $table) {
            // On ajoute la colonne status et on peut supprimer l'ancien is_active si tu veux
            $table->string('status')->default('Vide')->after('type');
            
            // Optionnel : supprimer l'ancienne colonne si elle ne sert plus
            if (Schema::hasColumn('buildings', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->boolean('is_active')->default(true);
        });
    }
};
