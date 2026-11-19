<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SORTIES AU PÂTURAGE — les ruminants qui se nourrissent eux-mêmes.
 *
 * Demande de l'exploitation : « parfois, on laisse les ruminants à l'air
 * libre pour qu'ils mangent en autonomie ; comment tracer cette activité ? »
 * Ni stock ni distribution : une SORTIE — quel lot, où, de quelle heure à
 * quelle heure, gardé ou en autonomie, combien de têtes sont parties et
 * combien sont revenues. Le temps du berger est imputé au lot comme celui
 * de l'alimentation brute (BatchCostAllocation).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grazing_sessions')) {
            return;
        }

        Schema::create('grazing_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('plot_id')->nullable()->constrained('plots')->nullOnDelete();
            $table->string('zone', 120)->nullable();
            $table->dateTime('left_at');
            $table->dateTime('returned_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('mode', 12);
            $table->unsignedInteger('heads_out');
            $table->unsignedInteger('heads_back');
            $table->unsignedSmallInteger('herder_minutes')->default(0);
            $table->decimal('hourly_cost_gnf', 12, 2)->default(0);
            $table->decimal('labor_cost_gnf', 14, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grazing_sessions');
    }
};
