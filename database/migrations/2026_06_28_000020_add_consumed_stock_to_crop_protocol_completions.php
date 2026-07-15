<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet à la validation d'une étape de DÉSTOCKER un intrant existant
 * (consommation depuis le stock « intrants » pré-approvisionné), et non plus
 * seulement d'enregistrer une charge.
 *
 * On mémorise le stock consommé pour pouvoir RESTITUER la quantité si la
 * validation est annulée ou recalculée (symétrie entrée/sortie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_protocol_completions', function (Blueprint $table) {
            $table->foreignId('consumed_stock_id')->nullable()->after('crop_input_id')
                ->constrained('stocks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crop_protocol_completions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consumed_stock_id');
        });
    }
};
