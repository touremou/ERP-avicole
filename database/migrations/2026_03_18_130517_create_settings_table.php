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
    if (Schema::hasTable('settings')) {
        return; // Superseded by 2026_06_05_000001_create_settings_table.php
    }

    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->text('value');
        $table->string('group');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
