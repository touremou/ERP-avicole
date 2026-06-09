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
        Schema::create('maintenance_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('mill_machine_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained(); // L'opérateur qui valide
    $table->float('hours_at_maintenance'); // Heures au compteur lors de l'entretien
    $table->text('description')->nullable(); // Notes (ex: Vidange, changement courroie)
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_logs');
    }
};
