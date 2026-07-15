<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustements de stock formels (démarque / inventaire).
 *
 * Trace structurée d'une correction d'inventaire : motif (casse, péremption,
 * vol, écart d'inventaire…), quantités avant/après, et VALEUR de l'impact
 * (|delta| × CMP figé au moment de l'ajustement) pour chiffrer la démarque.
 *
 * N'impacte PAS le P&L (pas de dépense postée) : la valorisation du stock et
 * l'imputation des achats sont hétérogènes selon les sources — pour éviter tout
 * double comptage, la démarque est un indicateur de pilotage, pas une écriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('type', 10);                 // perte | gain
            $table->string('reason', 40);               // inventaire, casse, peremption, vol…
            $table->decimal('quantity_before', 15, 3);
            $table->decimal('quantity_after', 15, 3);
            $table->decimal('delta', 15, 3);            // signé (négatif = perte)
            $table->decimal('unit_cost', 14, 2)->default(0); // CMP figé
            $table->decimal('value_impact', 14, 2)->default(0); // |delta| × unit_cost
            $table->date('adjustment_date');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'adjustment_date']);
            $table->index(['stock_id']);
            $table->index(['type', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
