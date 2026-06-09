<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained(); // Le bâtiment touché
            $table->foreignId('user_id')->constrained(); // L'agent qui signale
            
            $table->date('incident_date');
            $table->integer('mortality_count'); // Nombre de cadavres
            
            // Constats terrain
            $table->text('symptoms'); // Ex: "Fientes vertes, prostration, toux"
            $table->string('photo_path')->nullable(); // Photo de l'autopsie ou des fientes
            
            // Suivi Vétérinaire (Asynchrone)
            $table->enum('status', ['en_attente', 'diagnostique', 'resolu'])->default('en_attente');
            $table->string('suspected_disease')->nullable(); // Rempli par le véto plus tard
            $table->text('vet_prescription')->nullable(); 
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_incidents');
    }
};