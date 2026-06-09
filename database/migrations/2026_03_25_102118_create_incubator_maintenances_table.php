<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('incubator_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incubator_id')->constrained()->onDelete('cascade');
            $table->date('maintenance_date');
            $table->enum('type', ['Entretien', 'Réparation', 'Désinfection', 'Étalonnage']);
            $table->text('description')->nullable();
            $table->string('performed_by')->nullable(); // Nom de l'agent
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incubator_maintenances');
    }
};
