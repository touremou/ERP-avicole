<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $blueprint) {
            // Cette méthode ajoute automatiquement la colonne 'deleted_at'
            $blueprint->softDeletes(); 
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $blueprint) {
            $blueprint->dropSoftDeletes();
        });
    }
};