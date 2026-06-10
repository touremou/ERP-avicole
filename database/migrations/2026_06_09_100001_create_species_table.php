<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('species', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name_fr', 100);
            $table->string('local_name', 100)->nullable();
            $table->enum('family', ['volaille','petit_ruminant','grand_ruminant','aquaculture','porcin','lagomorphe','autre'])->default('volaille');
            $table->string('unit_label', 30)->default('Tête');
            $table->string('habitat_label', 50)->default('Bâtiment');
            $table->string('icon', 10)->nullable();
            $table->string('color', 30)->default('blue');
            $table->boolean('tracks_eggs')->default(false);
            $table->boolean('tracks_milk')->default(false);
            $table->boolean('tracks_water_quality')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('species'); }
};
