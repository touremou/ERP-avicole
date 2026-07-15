<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 mobile (cultures / abattoir / provenderie) — idempotence des
 * nouvelles opérations de sync qui ne créent PAS une ligne à uuid propre :
 *
 *  - `slaughter.execute` produit un SlaughterResult : on y ancre l'uuid de
 *    l'opération terrain (colonne `uuid`, comme les autres tables sync-ées) ;
 *  - `mill_production.complete` ne crée AUCUNE ligne (il mute l'OP) : l'uuid
 *    de la clôture est mémorisé sur l'OP (`completion_uuid`) pour distinguer
 *    un rejeu réseau (`already_synced`) d'une clôture concurrente (`conflict`).
 *
 * Index UNIQUE (nullable) : même doctrine que 2026_07_02_000001 — toute
 * idempotence applicative est doublée d'une contrainte en base.
 *
 * `harvest.create` et `crop_input.create` n'ont pas besoin de migration :
 * harvests / crop_inputs portent déjà un uuid unique (tables cultures).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('slaughter_results', 'uuid')) {
            Schema::table('slaughter_results', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            });
        }

        if (! Schema::hasColumn('mill_productions', 'completion_uuid')) {
            Schema::table('mill_productions', function (Blueprint $table) {
                $table->uuid('completion_uuid')->nullable()->unique()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('slaughter_results', 'uuid')) {
            Schema::table('slaughter_results', function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }

        if (Schema::hasColumn('mill_productions', 'completion_uuid')) {
            Schema::table('mill_productions', function (Blueprint $table) {
                $table->dropColumn('completion_uuid');
            });
        }
    }
};
