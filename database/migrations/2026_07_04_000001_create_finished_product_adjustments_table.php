<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des ajustements et éliminations de produits finis (abattoir).
 *
 * Avant : Log fichier uniquement — aucune trace requêtable en base d'une
 * correction de stock ou d'une élimination (péremption). Même exigence de
 * traçabilité que la démarque du magasin (stock-adjustments) : qui, quand,
 * quoi, pourquoi, de combien à combien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_product_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->index();
            $table->foreignId('finished_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('type', 20); // ajustement | elimination
            $table->decimal('old_kg', 10, 2);
            $table->decimal('new_kg', 10, 2);
            $table->string('reason', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_product_adjustments');
    }
};
