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
        Schema::create('mill_production_machine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mill_production_id')->constrained()->onDelete('cascade');
            $table->foreignId('mill_machine_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mill_production_machine');
    }
};
