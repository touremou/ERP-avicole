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
    Schema::table('raw_materials', function (Blueprint $table) {
        $table->decimal('energy_kcal', 8, 2)->default(0)->after('unit_cost');
        $table->decimal('protein_rate', 5, 2)->default(0);
        $table->decimal('lysine_rate', 5, 2)->default(0);
        $table->decimal('calcium_rate', 5, 2)->default(0);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            //
        });
    }
};
