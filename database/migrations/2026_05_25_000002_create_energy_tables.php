<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('energy_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                              // "Groupe Perkins 100kVA", "EDG Réseau", "Solaire Bât. A"
            $table->enum('type', ['edg', 'groupe', 'solaire']);
            $table->string('brand')->nullable();                                 // Perkins, Caterpillar, etc.
            $table->string('model')->nullable();
            $table->decimal('capacity_kva', 8, 2)->nullable();
            $table->enum('fuel_type', ['gasoil', 'essence'])->nullable();       // Null pour EDG/solaire
            $table->decimal('fuel_tank_capacity', 10, 2)->nullable();           // Capacité cuve gasoil (litres)
            $table->decimal('current_fuel_level', 10, 2)->nullable();           // Niveau cuve actuel
            $table->decimal('total_hours_run', 10, 2)->default(0);
            $table->integer('maintenance_interval_hours')->default(250);
            $table->timestamp('last_maintenance_at')->nullable();
            $table->timestamp('next_maintenance_at')->nullable();
            $table->enum('status', ['operationnel', 'maintenance', 'panne'])->default('operationnel');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('energy_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('energy_source_id')->constrained()->cascadeOnDelete();
            $table->date('reading_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('hours_run', 6, 2)->default(0);                     // Heures fonctionnement du jour
            $table->decimal('fuel_consumed_liters', 10, 2)->nullable();         // Gasoil consommé (groupe)
            $table->decimal('kwh_produced', 10, 2)->nullable();                 // kWh estimés
            $table->decimal('cost', 12, 2)->default(0);                         // Coût journalier
            $table->decimal('outage_hours', 6, 2)->default(0);                  // Heures coupure EDG
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['energy_source_id', 'reading_date'], 'energy_reading_unique_per_day');
            $table->index('reading_date');
        });

        Schema::create('fuel_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('energy_source_id')->constrained()->restrictOnDelete();
            $table->date('purchase_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_liters', 10, 2);
            $table->decimal('unit_price', 10, 2);                               // GNF/litre
            $table->decimal('total_cost', 14, 2);
            $table->string('supplier')->nullable();                              // Station service, fournisseur
            $table->string('receipt_reference')->nullable();                     // N° reçu
            $table->decimal('fuel_level_after', 10, 2)->nullable();             // Niveau cuve après remplissage
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_purchases');
        Schema::dropIfExists('energy_readings');
        Schema::dropIfExists('energy_sources');
    }
};
