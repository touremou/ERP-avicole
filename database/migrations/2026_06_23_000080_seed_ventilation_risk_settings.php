<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paramètres de l'alerte composite « risque ventilation » (P3) pour les
     * bases existantes : seuil de chaleur et seuil de dépendance au groupe.
     * Idempotent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $rows = [
            ['key' => 'ventilation_heat_threshold', 'value' => '36', 'label' => 'Seuil chaleur risque ventilation', 'unit' => '°C',    'order' => 6],
            ['key' => 'ventilation_reliance_hours', 'value' => '5',  'label' => 'Sollicitation groupe (dépendance)', 'unit' => 'h/jour', 'order' => 7],
        ];

        foreach ($rows as $r) {
            DB::table('settings')->updateOrInsert(
                ['key' => $r['key']],
                [
                    'group'         => 'energie',
                    'value'         => $r['value'],
                    'type'          => 'number',
                    'label'         => $r['label'],
                    'unit'          => $r['unit'],
                    'display_order' => $r['order'],
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->whereIn('key', [
            'ventilation_heat_threshold', 'ventilation_reliance_hours',
        ])->delete();
    }
};
