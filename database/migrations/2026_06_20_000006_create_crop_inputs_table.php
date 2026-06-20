<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTRANTS DE CULTURE (module: cultures).
 *
 * Registre itémisé des charges d'un cycle de culture (semences, engrais,
 * produits phytosanitaires, main d'œuvre, irrigation…). Pendant végétal des
 * `feed_purchases` côté élevage : permet de fiabiliser la marge nette d'un
 * cycle en détaillant ses coûts, plutôt qu'une saisie forfaitaire unique.
 *
 * Un intrant peut, en option, alimenter le stock (catégorie « intrants »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_inputs', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type')->default('autre');       // cf. CropInput::TYPES
            $table->string('name');                          // libellé de l'intrant
            $table->decimal('quantity', 12, 3)->default(0);
            $table->string('unit')->default('kg');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0); // quantity × unit_cost (ou saisi)

            $table->date('input_date');

            // Intégration stock (optionnelle).
            $table->boolean('synced_to_stock')->default(false);
            $table->string('stock_item_name')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'input_date']);
            $table->index('crop_cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_inputs');
    }
};
