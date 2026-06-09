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
        Schema::table('batches', function (Blueprint $table) {
            // On rend le champ nullable
            $table->decimal('avg_weight_start', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->decimal('avg_weight_start', 8, 2)->nullable(false)->change();
        });
    }
};
