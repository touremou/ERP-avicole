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
        Schema::create('mill_machines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // Broyeur, Mélangeur, Ensacheuse
            $table->decimal('capacity_per_hour', 10, 2); // Capacité en kg/h
            $table->decimal('total_hours_run', 12, 2)->default(0); // Compteur horaire
            $table->date('last_maintenance')->nullable();
            $table->enum('status', ['Opérationnel', 'Maintenance', 'En Panne'])->default('Opérationnel');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mill_machines');
    }
};
