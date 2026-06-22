<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache un protocole / itinéraire technique (optionnel) à un cycle de culture.
 * nullOnDelete : la suppression d'un protocole n'efface pas l'historique du cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->foreignId('crop_protocol_id')->nullable()->after('campaign_id')
                ->constrained('crop_protocols')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crop_protocol_id');
        });
    }
};
