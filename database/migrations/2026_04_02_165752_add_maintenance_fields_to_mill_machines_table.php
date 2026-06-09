<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mill_machines', function (Blueprint $table) {
            // Ajout de la colonne manquante après 'total_hours_run'
            $table->integer('maintenance_interval_hours')->default(500)->after('total_hours_run');
        });
    }

    public function down(): void
    {
        Schema::table('mill_machines', function (Blueprint $table) {
            $table->dropColumn('maintenance_interval_hours');
        });
    }
};