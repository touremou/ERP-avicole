<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // Terminal : php artisan make:migration add_sanitary_fields_to_buildings_table

    public function up()
    {
        Schema::table('buildings', function (Blueprint $table) {
            // Enregistre la date du dernier début de désinfection
            $table->timestamp('disinfection_started_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            //
        });
    }
};
