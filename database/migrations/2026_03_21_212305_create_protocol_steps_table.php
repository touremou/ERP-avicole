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
        Schema::create('protocol_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained()->onDelete('cascade');
            $table->integer('day_number'); // Ex: 1, 7, 14, 21
            $table->string('action_name'); // Ex: Vaccin Gumboro
            $table->enum('type', ['Vaccin', 'Traitement', 'Vitamine', 'Désinfection']);
            $table->string('product_suggested')->nullable();
            $table->string('method')->nullable(); // Ex: Eau de boisson
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protocol_steps');
    }
};
