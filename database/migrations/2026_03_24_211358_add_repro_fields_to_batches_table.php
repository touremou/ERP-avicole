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
            // Quantités par sexe
            if (!Schema::hasColumn('batches', 'qty_males')) {
                $table->integer('qty_males')->default(0)->after('qty_alive');
            }
            if (!Schema::hasColumn('batches', 'qty_females')) {
                $table->integer('qty_females')->default(0)->after('qty_males');
            }
            
            // Indicateurs techniques
            if (!Schema::hasColumn('batches', 'mating_ratio')) {
                $table->decimal('mating_ratio', 8, 2)->default(0)->after('qty_females');
            }
            if (!Schema::hasColumn('batches', 'avg_weight_start')) {
                $table->decimal('avg_weight_start', 8, 2)->default(0)->after('mating_ratio');
            }
            if (!Schema::hasColumn('batches', 'planned_density')) {
                $table->decimal('planned_density', 8, 2)->default(0)->after('avg_weight_start');
            }
            if (!Schema::hasColumn('batches', 'arrival_mortality_rate')) {
                $table->decimal('arrival_mortality_rate', 8, 2)->default(0)->after('planned_density');
            }
            
            // État des poussins
            if (!Schema::hasColumn('batches', 'chick_state')) {
                $table->string('chick_state')->default('Normal')->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // On ne supprime que ce qui a été ajouté par précaution
            $columns = ['qty_males', 'qty_females', 'mating_ratio', 'avg_weight_start', 'planned_density', 'arrival_mortality_rate', 'chick_state'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

};
