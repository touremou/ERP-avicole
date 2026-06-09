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
            Schema::create('production_norms', function (Blueprint $row) {
        $row->id();
        // Type de production (chair, ponte, repro, poussiniere)
        $row->string('batch_type'); 
        $row->integer('week_number'); // Semaine 1, 2, ... 80
        
        // Indicateurs cibles (Normes)
        $row->decimal('target_weight', 8, 2)->nullable(); // en grammes
        $row->decimal('target_feed_daily', 8, 2)->nullable(); // g/sujet/jour
        $row->decimal('target_water_daily', 8, 2)->nullable(); // ml/sujet/jour
        $row->decimal('target_laying_rate', 5, 2)->default(0); // % pour ponte/repro
        
        // Phase correspondante
        $row->string('phase_name'); // Démarrage, Croissance, Ponte, etc.
        
        $row->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_norms');
    }
};
