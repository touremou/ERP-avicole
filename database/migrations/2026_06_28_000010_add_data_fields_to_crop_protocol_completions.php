<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrichit la validation d'une étape d'itinéraire technique avec les DONNÉES
 * RÉELLES collectées lors de la réalisation : coût engagé, quantité/produit
 * utilisé, et lien éventuel vers l'intrant de cycle créé.
 *
 * Boucle la validation d'étape avec la comptabilité du cycle : une étape
 * coûteuse (fertilisation, traitement…) peut être comptabilisée comme intrant
 * (crop_input_id), son coût alimentant alors directement la marge du cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_protocol_completions', function (Blueprint $table) {
            $table->decimal('cost', 14, 2)->nullable()->after('notes');
            $table->decimal('quantity', 12, 3)->nullable()->after('cost');
            $table->string('unit', 20)->nullable()->after('quantity');
            $table->foreignId('crop_input_id')->nullable()->after('unit')
                ->constrained('crop_inputs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crop_protocol_completions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crop_input_id');
            $table->dropColumn(['cost', 'quantity', 'unit']);
        });
    }
};
