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
        // 1. On s'assure d'abord que start_date existe (fondation du lot)
        if (!Schema::hasColumn('batches', 'start_date')) {
            $table->date('start_date')->nullable()->after('id');
        }

        // 2. On ajoute building_id si absent
        if (!Schema::hasColumn('batches', 'building_id')) {
            $table->foreignId('building_id')->nullable()->constrained()->onDelete('set null');
        }
        
        // 3. On ajoute la phase après le type (ou après start_date si type absent)
        if (!Schema::hasColumn('batches', 'production_phase')) {
            $table->string('production_phase')->default('demarrage');
        }

        // 4. On ajoute transfer_date après start_date (qui existe maintenant forcément)
        if (!Schema::hasColumn('batches', 'transfer_date')) {
            $table->date('transfer_date')->nullable()->after('start_date');
        }

        // 5. Protocole et Historique
        if (!Schema::hasColumn('batches', 'current_protocol_id')) {
            $table->foreignId('current_protocol_id')->nullable()->constrained('protocols')->onDelete('set null');
        }

        if (!Schema::hasColumn('batches', 'transfer_history')) {
            $table->json('transfer_history')->nullable();
        }
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            //
        });
    }
};
