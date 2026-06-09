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
        Schema::create('formulas', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // ex: "Aliment Croissance Poulet"
            $table->string('code')->unique(); // ex: FORM-CH-01
            $table->string('target_type'); // chair, ponte, etc.
            $table->decimal('total_batch_weight', 10, 2)->default(1000); // Base de calcul (ex: 1000kg)
            $table->text('instructions')->nullable();
            $table->boolean('is_locked')->default(false); // Si verrouillé, on ne peut plus modifier la recette
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('formulas');
    }
};
