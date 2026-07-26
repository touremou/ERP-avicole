<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4 — Cultures : DÉLAI AVANT RÉCOLTE (DAR, pre-harvest interval) d'un
 * traitement phytosanitaire. Pendant exact du délai d'attente en élevage
 * (M1) : après une pulvérisation, la récolte est interdite avant l'échéance
 * fixée par la notice, sous peine de résidus dans la production.
 * Nullable : les intrants sans DAR (engrais, irrigation, main d'œuvre) et les
 * saisies historiques restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_inputs', function (Blueprint $table) {
            $table->unsignedSmallInteger('preharvest_days')->nullable()->after('input_date');
        });

        // M4 — Provenderie : uuid de CRÉATION d'un OP lancé depuis le terrain
        // (distinct de completion_uuid, qui idempotente la clôture).
        Schema::table('mill_productions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('crop_inputs', function (Blueprint $table) {
            $table->dropColumn('preharvest_days');
        });

        Schema::table('mill_productions', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
