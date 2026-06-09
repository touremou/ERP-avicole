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
    Schema::create('batch_tasks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('batch_id')->constrained()->onDelete('cascade');
        
        // Détails de l'action issue du protocole
        $table->string('action_name');
        $table->string('type'); // Vaccin, Vitamine, etc.
        $table->string('method')->default('Eau de boisson');
        
        // Calendrier
        $table->integer('day_number'); // J+X
        $table->date('planned_date');  // Date réelle calculée (Start + Day)
        
        // État ERP
        $table->boolean('is_completed')->default(false);
        $table->timestamp('completed_at')->nullable();
        $table->string('operator_signature')->nullable(); // Nom de celui qui a validé
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_tasks');
    }
};
