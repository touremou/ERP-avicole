<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {/*
        Schema::create('daily_checks', function (Blueprint $table) {
            $table->id();
            // On lie le suivi à une bande (batch). Si la bande est supprimée, les suivis le sont aussi.
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            
            $table->date('check_date'); // Date du jour du suivi
            $table->integer('mortality')->default(0); // Nombre de morts déclarés
            $table->decimal('feed_consumed', 8, 2)->default(0); // Quantité d'aliment (kg)
            $table->integer('eggs_collected')->nullable(); // Optionnel (pour les pondeuses)
            $table->text('observations')->nullable(); // Remarques techniques
            
            $table->timestamps();
        });*/
        Schema::create('daily_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            $table->date('check_date');
            
            // Statistiques vitales (unsigned pour bloquer les négatifs au niveau SQL)
            $table->integer('mortality')->unsigned()->default(0);
            $table->decimal('feed_consumed', 8, 2)->unsigned()->default(0); // en Kg
            $table->decimal('water_consumed', 8, 2)->unsigned()->nullable(); // en Litres
            $table->decimal('avg_weight', 8, 3)->unsigned()->nullable(); // en Kg
            
            // Santé et Environnement
            $table->decimal('temperature', 4, 1)->nullable(); 
            $table->text('observations')->nullable();
            
            $table->string('treatment_type')->nullable(); // Ex: Vaccin, Antibio, Vitamine
            $table->string('treatment_name')->nullable(); // Ex: Gumboro, Alvityl...
            
            $table->timestamps();
            
            // Sécurité : Une seule saisie par lot et par jour
            $table->unique(['batch_id', 'check_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checks');
    }
};