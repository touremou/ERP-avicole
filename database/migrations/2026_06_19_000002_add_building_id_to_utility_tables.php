<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('water_readings', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('water_source_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('energy_readings', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('energy_source_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('fuel_purchases', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('energy_source_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('water_readings', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Building::class);
            $table->dropColumn('building_id');
        });

        Schema::table('energy_readings', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Building::class);
            $table->dropColumn('building_id');
        });

        Schema::table('fuel_purchases', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Building::class);
            $table->dropColumn('building_id');
        });
    }
};
