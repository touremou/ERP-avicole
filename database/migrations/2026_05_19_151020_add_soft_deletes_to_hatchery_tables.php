<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ajoute la colonne deleted_at à la table des couveuses
        Schema::table('incubators', function (Blueprint $table) {
            if (!Schema::hasColumn('incubators', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Par sécurité, on s'assure qu'elle y est aussi pour les cycles d'incubation
        Schema::table('incubations', function (Blueprint $table) {
            if (!Schema::hasColumn('incubations', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('incubators', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        
        Schema::table('incubations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};