<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rend la planification multiespèces.
 *
 * Jusqu'ici une bande planifiée ne portait que `batch_type` (slug volaille) ;
 * à l'activation, le lot créé n'avait NI species_id NI production_type_id,
 * cassant toute la logique multiespèces en aval. On rattache désormais
 * l'espèce et le type de production à la planification, propagés au lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_batches', function (Blueprint $table) {
            $table->foreignId('species_id')->nullable()->after('batch_type')->constrained()->nullOnDelete();
            $table->foreignId('production_type_id')->nullable()->after('species_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('planned_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_type_id');
            $table->dropConstrainedForeignId('species_id');
        });
    }
};
