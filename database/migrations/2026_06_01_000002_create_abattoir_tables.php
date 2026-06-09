<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── ORDRES D'ABATTAGE ───
        Schema::create('slaughter_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();                           // ABA-2026-000001
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->date('planned_date');
            $table->date('actual_date')->nullable();
            $table->integer('planned_quantity');                                 // Nb sujets prévus
            $table->integer('actual_quantity')->nullable();                      // Nb sujets réels
            $table->decimal('total_live_weight_kg', 10, 2)->nullable();
            $table->enum('status', ['planifie', 'en_cours', 'termine', 'annule'])->default('planifie');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'planned_date']);
        });

        // ─── RÉSULTATS D'ABATTAGE ───
        Schema::create('slaughter_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slaughter_order_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_carcass_weight_kg', 10, 2);
            $table->decimal('carcass_yield_percent', 5, 2);                    // Rendement carcasse
            $table->integer('condemned_count')->default(0);                     // Saisies sanitaires
            $table->text('condemned_reason')->nullable();
            $table->decimal('avg_live_weight_kg', 6, 3)->nullable();
            $table->decimal('avg_carcass_weight_kg', 6, 3)->nullable();
            $table->date('execution_date');
            $table->text('inspector_notes')->nullable();
            $table->timestamps();
        });

        // ─── SESSIONS DE DÉCOUPE ───
        Schema::create('cutting_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slaughter_order_id')->constrained()->restrictOnDelete();
            $table->date('session_date');
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->decimal('total_input_kg', 10, 2);                          // Poids carcasses entrées
            $table->decimal('total_output_kg', 10, 2)->default(0);             // Somme découpes
            $table->decimal('loss_kg', 8, 2)->default(0);
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ─── PRODUITS DE DÉCOUPE ───
        Schema::create('cut_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutting_session_id')->constrained()->cascadeOnDelete();
            $table->enum('product_type', ['entier', 'cuisse', 'aile', 'poitrine', 'dos', 'abats', 'foie', 'gesier', 'autre']);
            $table->string('product_name');
            $table->decimal('quantity_kg', 10, 2);
            $table->integer('quantity_pieces')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();                  // GNF/kg
            $table->enum('destination', ['stock_frais', 'stock_congele', 'transformation', 'vente_directe'])->default('stock_frais');
            $table->timestamps();
        });

        // ─── TRANSFORMATIONS (fumé, grillé, mariné) ───
        Schema::create('transformations', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number');                                     // TRANS-2026-000001
            $table->string('product_source');                                   // "Poulet entier", "Cuisses"
            $table->enum('transformation_type', ['fume', 'grille', 'marine', 'autre']);
            $table->decimal('input_kg', 10, 2);
            $table->decimal('output_kg', 10, 2)->nullable();
            $table->decimal('yield_percent', 5, 2)->nullable();
            $table->date('production_date');
            $table->date('expiry_date')->nullable();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->decimal('production_cost', 12, 2)->default(0);             // Charbon, épices, etc.
            $table->enum('status', ['en_cours', 'termine', 'annule'])->default('en_cours');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ─── STOCK PRODUITS FINIS ───
        Schema::create('finished_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');                                     // "Poulet entier frais", "Cuisses fumées"
            $table->enum('product_type', [
                'entier_frais', 'entier_congele',
                'cuisse', 'aile', 'poitrine', 'dos',
                'abats', 'foie', 'gesier',
                'fume', 'grille', 'marine',
                'autre'
            ]);
            $table->decimal('current_quantity_kg', 10, 2)->default(0);
            $table->integer('current_quantity_pieces')->default(0);
            $table->enum('unit', ['kg', 'piece'])->default('kg');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->enum('storage_location', ['frais', 'congele', 'vitrine', 'fumoir'])->default('frais');
            $table->date('expiry_date')->nullable();
            $table->decimal('alert_threshold_kg', 8, 2)->default(0);
            $table->string('batch_reference')->nullable();                      // Traçabilité
            $table->timestamps();

            $table->unique(['product_name', 'product_type', 'storage_location'], 'fp_unique');
            $table->index('product_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_products');
        Schema::dropIfExists('transformations');
        Schema::dropIfExists('cut_products');
        Schema::dropIfExists('cutting_sessions');
        Schema::dropIfExists('slaughter_results');
        Schema::dropIfExists('slaughter_orders');
    }
};
