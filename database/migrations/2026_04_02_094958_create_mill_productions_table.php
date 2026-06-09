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
        Schema::create('mill_productions', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique(); // Numéro de lot de fabrication
            $table->foreignId('formula_id')->constrained();
            $table->foreignId('machine_id')->constrained('mill_machines');
            $table->decimal('quantity_produced', 12, 2); // Quantité totale en kg
            $table->decimal('real_cost_per_kg', 12, 2); // Coût réel après production
            $table->foreignId('operator_id')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->enum('status', ['Planifié', 'En cours', 'Terminé', 'Échec'])->default('Planifié');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mill_productions');
    }
};
