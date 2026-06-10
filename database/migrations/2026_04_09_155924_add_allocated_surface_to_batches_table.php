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
    if (!Schema::hasColumn('batches', 'allocated_surface')) {
        Schema::table('batches', function (Blueprint $table) {
            // Ajout de la surface allouée (decimal pour la précision, ex: 120.5 m²)
            $table->decimal('allocated_surface', 10, 2)->nullable()->after('building_id');
        });
    }
}

public function down(): void
{
    Schema::table('batches', function (Blueprint $table) {
        $table->dropColumn('allocated_surface');
    });
}
};
