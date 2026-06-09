<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration Fusionnée : Création de la table egg_productions avec supports décimaux.
     */
    public function up(): void
    {
        Schema::create('egg_productions', function (Blueprint $table) {
            $table->id();
            
            // Relation avec le lot (Batch)
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            $table->date('production_date');
            
            // Quantités Brutes (Toujours en Unités/Entiers)
            $table->integer('total_eggs_collected')->default(0);
            $table->integer('broken_eggs')->default(0);
            $table->integer('small_eggs')->default(0);
            $table->integer('incubable_eggs')->default(0);

            // Calibrage (Décimal pour supporter les alvéoles ex: 1.5 alv)
            $table->decimal('grade_xl', 10, 2)->default(0);
            $table->decimal('grade_l', 10, 2)->default(0);
            $table->decimal('grade_m', 10, 2)->default(0);
            $table->decimal('grade_s', 10, 2)->default(0);
            
            // Statut de tri pour le Dashboard
            $table->boolean('is_graded')->default(false);

            // Performance (Hen Day Production)
            $table->decimal('laying_rate', 5, 2)->default(0);
            
            $table->text('observations')->nullable();
            $table->timestamps();

            // Index pour les performances
            $table->index(['batch_id', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_productions');
    }
};