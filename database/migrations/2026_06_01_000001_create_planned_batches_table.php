<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->enum('batch_type', ['chair', 'ponte', 'reproducteur']);
            $table->string('model_name')->nullable();                           // Cobb500, ISA Brown, etc.
            $table->integer('planned_quantity');
            $table->date('planned_arrival_date');                                // J0
            $table->date('planned_end_date');                                   // Abattage ou réforme
            $table->date('sanitary_void_start')->nullable();                    // Début vide sanitaire
            $table->date('sanitary_void_end')->nullable();                      // Fin vide sanitaire
            $table->date('chick_order_deadline')->nullable();                   // Date limite commande poussins
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['planifie', 'commande', 'en_cours', 'termine', 'annule'])->default('planifie');
            $table->foreignId('actual_batch_id')->nullable();                   // Lien vers le lot réel
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['building_id', 'status']);
            $table->index('planned_arrival_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_batches');
    }
};
