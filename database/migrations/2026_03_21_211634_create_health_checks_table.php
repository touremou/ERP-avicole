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
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            $table->date('intervention_date');
            $table->enum('type', ['Vaccin', 'Traitement', 'Vitamine', 'Désinfection']);
            $table->string('product_name'); // Ex: Gumboro, Aliseryl
            $table->string('dosage')->nullable(); // Ex: 1 flacon / 1000 sujets
            $table->enum('mode_administration', ['Eau de boisson', 'Injection', 'Nébulisation', 'Aliment']);
            $table->text('observations')->nullable();
            // MODIFICATION ICI : Ajoute ->nullable()
            $table->decimal('cost', 10, 2)->nullable()->default(0);// Pour le calcul de rentabilité
            $table->string('veterinary_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
