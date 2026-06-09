<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('egg_movements', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['vente', 'don', 'ajustement', 'casse_magasin']);
            $table->string('grade'); // XL, L, M, S, ou BRUT
            $table->integer('quantity');
            $table->string('observations')->nullable();
            $table->timestamps();
        });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egg_movements');
    }
};
