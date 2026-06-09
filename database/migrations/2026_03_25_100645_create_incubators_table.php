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
        Schema::create('incubators', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nom de la couveuse
            $table->integer('capacity')->default(0); // Capacité max
            $table->enum('status', ['Disponible', 'Occupé', 'Maintenance'])->default('Disponible');
            $table->timestamps();
        });

        // Optionnel : Ajouter la colonne incubator_id à ta table incubations existante
        Schema::table('incubations', function (Blueprint $table) {
            if (!Schema::hasColumn('incubations', 'incubator_id')) {
                $table->foreignId('incubator_id')->nullable()->after('batch_id')->constrained()->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incubators');
    }
};
