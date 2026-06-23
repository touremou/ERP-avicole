<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('energy_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('maintenance_date');
            $table->string('type')->default('vidange');                  // vidange, filtres, inspection, reparation, contrat
            $table->text('description')->nullable();
            $table->decimal('cost', 14, 2)->nullable();                  // Coût de l'intervention (GNF)
            $table->string('technician')->nullable();                    // Technicien / société
            $table->decimal('hours_at_maintenance', 10, 2)->default(0); // Compteur heures lors de l'intervention
            $table->unsignedInteger('next_interval_hours')->nullable(); // Override de l'intervalle pour cette révision
            $table->foreignId('task_assignment_id')->nullable()->constrained('task_assignments')->nullOnDelete();
            $table->timestamps();

            $table->index(['energy_source_id', 'maintenance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_logs');
    }
};
