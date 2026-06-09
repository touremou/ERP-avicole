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
    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->string('provider_id')->unique();
        $table->string('name');
        $table->string('type');
        $table->string('domain')->nullable();
        $table->string('phone');
        $table->string('email')->nullable();
        $table->string('address')->nullable();
        $table->string('rccm')->nullable();
        $table->string('nif')->nullable();
        $table->string('payment_terms')->nullable();
        $table->string('reliability')->default('Moyen');
        $table->string('status')->default('Actif');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
