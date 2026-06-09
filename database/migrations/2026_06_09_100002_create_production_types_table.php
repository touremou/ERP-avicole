<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('production_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('species')->cascadeOnDelete();
            $table->string('slug', 50);
            $table->string('name_fr', 100);
            $table->json('metrics_enabled')->nullable();
            $table->string('kpi_primary', 50)->default('fcr');
            $table->integer('cycle_days_default')->default(45);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['species_id', 'slug']);
        });
    }
    public function down(): void { Schema::dropIfExists('production_types'); }
};
