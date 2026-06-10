<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collecte de lait par lot (laiterie caprine — valeur ajoutée Fouta Djallon).
 *
 * Le prix du lait de chèvre en GNF est volatil : on stocke le prix unitaire
 * AU MOMENT de la collecte (snapshot par lot/jour) pour un suivi de marge
 * fidèle. Traites matin/soir agrégées en total journalier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('production_date');
            $table->decimal('morning_liters', 10, 2)->default(0);   // traite du matin
            $table->decimal('evening_liters', 10, 2)->default(0);   // traite du soir
            $table->decimal('total_liters', 10, 2)->default(0);     // matin + soir (dénormalisé)
            $table->decimal('unit_price', 12, 2)->default(0);       // GNF / litre au moment de la collecte
            $table->unsignedInteger('milking_females')->nullable(); // nb de femelles traites (rendement/tête)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'production_date']);
            $table->index('production_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_productions');
    }
};
