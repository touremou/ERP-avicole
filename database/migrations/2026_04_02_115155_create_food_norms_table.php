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
    Schema::create('food_norms', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // ex: Démarrage Ross 308, Ponte Phase 1
        $table->string('animal_type'); // chair, ponte, etc.
        $table->string('phase'); // démarrage, croissance, finition
        
        // Cibles Nutritionnelles (Valeurs standards)
        $table->decimal('target_em', 8, 2)->comment('Énergie Métabolisable kcal/kg');
        $table->decimal('target_pb', 5, 2)->comment('Protéine Brute %');
        $table->decimal('target_lys', 5, 2)->comment('Lysine %');
        $table->decimal('target_meth', 5, 2)->comment('Méthionine %');
        $table->decimal('target_ca', 5, 2)->comment('Calcium %');
        $table->decimal('target_p', 5, 2)->comment('Phosphore %');
        
        $table->decimal('target_price_kg', 10, 2)->nullable(); // Prix cible théorique
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_norms');
    }
};
