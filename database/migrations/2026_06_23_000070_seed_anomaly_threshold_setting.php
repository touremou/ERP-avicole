<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le paramètre de seuil de détection d'anomalie de consommation
     * (P3 — analytique) pour les bases existantes. Idempotent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->updateOrInsert(
            ['key' => 'anomaly_threshold_pct'],
            [
                'group'         => 'energie',
                'value'         => '50',
                'type'          => 'number',
                'label'         => 'Seuil anomalie conso (écart)',
                'unit'          => '%',
                'display_order' => 5,
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->where('key', 'anomaly_threshold_pct')->delete();
    }
};
