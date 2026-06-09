<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->enum('product_type', ['oeufs', 'volaille_vivante', 'volaille_abattue', 'fumier', 'aliment', 'materiel', 'autre']);
            $table->string('product_name');                                     // "Œufs L", "Poulet vif", "Chair Finition"
            $table->enum('category', ['grossiste', 'detail', 'standard'])->default('standard');
            $table->enum('unit', ['alveole', 'unite', 'kg', 'piece', 'sac', 'voyage'])->default('piece');
            $table->decimal('unit_price', 12, 2);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_type', 'product_name', 'category'], 'price_list_unique');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
