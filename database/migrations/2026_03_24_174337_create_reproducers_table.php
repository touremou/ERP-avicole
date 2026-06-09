<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        // Table des Incubations (Mise en couveuse)
        Schema::create('incubations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            $table->string('code_incubation')->unique(); // Ex: INC-2026-001
            $table->date('start_date');
            $table->date('hatch_date_expected'); // start_date + 21 jours
            $table->integer('eggs_count'); // Nb d'œufs mis en charge
            $table->integer('fertile_eggs')->default(0); // Rempli après mirage
            $table->integer('hatched_chicks')->default(0); // Rempli à l'éclosion
            $table->decimal('fertility_rate', 5, 2)->default(0);
            $table->decimal('hatchability_rate', 5, 2)->default(0);
            $table->enum('status', ['incubation', 'mirage_fait', 'clos', 'echec'])->default('incubation');
            $table->timestamps();
        });
    }
};