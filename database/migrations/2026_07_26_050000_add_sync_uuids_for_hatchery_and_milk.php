<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 — Élevage avancé au terrain, idempotence du push offline :
 *  - incubations.mirage_uuid / hatch_uuid : ces opérations MODIFIENT un cycle
 *    existant (comme completion_uuid pour la provenderie) — un uuid dédié par
 *    étape est plus sûr qu'un test de statut, et laisse le web corriger un
 *    mirage sans que le mobile ne rejoue par erreur ;
 *  - milk_productions.uuid : collecte de lait créée au terrain.
 * Les relevés eau/énergie sont naturellement idempotents (updateOrCreate par
 * source + jour), aucun uuid nécessaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            $table->uuid('mirage_uuid')->nullable()->unique()->after('uuid');
            $table->uuid('hatch_uuid')->nullable()->unique()->after('mirage_uuid');
        });

        Schema::table('milk_productions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            $table->dropColumn(['mirage_uuid', 'hatch_uuid']);
        });

        Schema::table('milk_productions', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
