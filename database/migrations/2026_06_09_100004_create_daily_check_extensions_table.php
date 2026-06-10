<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('daily_check_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_check_id')->constrained('daily_checks')->cascadeOnDelete();
            // Ruminants
            $table->unsignedInteger('qty_born')->nullable();
            $table->unsignedInteger('qty_weaned')->nullable();
            $table->unsignedInteger('qty_sold_live')->nullable();
            $table->decimal('milk_liters', 8, 2)->nullable();
            $table->decimal('milk_fat_pct', 4, 2)->nullable();
            // Aquaculture
            $table->decimal('water_temp', 4, 1)->nullable();
            $table->decimal('water_ph', 3, 1)->nullable();
            $table->decimal('water_o2_ppm', 5, 2)->nullable();
            $table->decimal('water_ammonia_ppm', 5, 3)->nullable();
            $table->decimal('biomass_kg', 10, 2)->nullable();
            $table->decimal('survival_rate', 5, 2)->nullable();
            // Extension libre
            $table->json('extra_data')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('daily_check_extensions'); }
};
