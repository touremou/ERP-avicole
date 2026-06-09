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
    Schema::table('batches', function (Blueprint $table) {
        // On définit une valeur par défaut (0) pour les champs qui bloquent
        $table->decimal('avg_weight_start', 8, 2)->default(0)->change();
        $table->decimal('planned_density', 8, 2)->default(0)->change();
        
        // Optionnel : tu peux aussi en profiter pour d'autres champs techniques
        // $table->decimal('votre_autre_champ', 8, 2)->default(0)->change();
    });
}

public function down(): void
{
    Schema::table('batches', function (Blueprint $table) {
        $table->decimal('avg_weight_start', 8, 2)->default(null)->change();
        $table->decimal('planned_density', 8, 2)->default(null)->change();
    });
}
};
