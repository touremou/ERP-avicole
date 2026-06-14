<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collecte d'œufs hors-ligne (mode terrain).
 *
 * La collecte cumule plusieurs passages dans une même journée (une seule
 * ligne par batch_id + production_date). Pour que la synchronisation d'un
 * passage saisi hors-ligne soit idempotente (un même envoi peut être rejoué
 * par le réseau), on mémorise sur la ligne du jour la liste des UUID des
 * passages déjà appliqués. Le serveur n'applique le cumul qu'une seule fois
 * par UUID.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('egg_productions', function (Blueprint $table) {
            if (! Schema::hasColumn('egg_productions', 'synced_uuids')) {
                $table->json('synced_uuids')->nullable()->after('observations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('egg_productions', function (Blueprint $table) {
            if (Schema::hasColumn('egg_productions', 'synced_uuids')) {
                $table->dropColumn('synced_uuids');
            }
        });
    }
};
