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
    Schema::table('mill_productions', function (Blueprint $table) {
        // On autorise la valeur nulle pour la création de l'OP
        $table->decimal('real_cost_per_kg', 12, 2)->nullable()->change();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mill_productions', function (Blueprint $table) {
            //
        });
    }
};
