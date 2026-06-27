<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Préfixe de numérotation des tickets de caisse (vente comptant / POS).
 *
 * Complète les préfixes de ventes existants (ventes.invoice_prefix_bl / _tva)
 * avec ventes.invoice_prefix_pos, consommé par DocumentNumberingService pour le
 * schéma `sale_pos`. Idempotent : on n'insère que si la clé est absente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')
            ->where('group', 'ventes')
            ->where('key', 'invoice_prefix_pos')
            ->whereNull('farm_id')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('settings')->insert([
            'group'         => 'ventes',
            'key'           => 'invoice_prefix_pos',
            'value'         => 'TKT',
            'type'          => 'string',
            'label'         => 'Préfixe ticket de caisse (comptant)',
            'display_order' => 3,
            'options'       => null,
            'description'   => 'Format : TKT-2026-000001',
            'is_sensitive'  => false,
            'farm_id'       => null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'ventes')
            ->where('key', 'invoice_prefix_pos')
            ->delete();
    }
};
