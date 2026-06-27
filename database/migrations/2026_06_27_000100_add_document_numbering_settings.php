<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Préfixes de numérotation configurables (groupe « Numérotation »).
 *
 * Les ventes conservent leurs préfixes historiques dans le groupe « ventes »
 * (ventes.invoice_prefix_bl / _tva) ; on ajoute ici les préfixes des autres
 * documents, jusqu'ici codés en dur, pilotés par DocumentNumberingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $now = now();

        $settings = [
            ['group' => 'numbering', 'key' => 'sale_return_prefix',         'value' => 'RET',   'type' => 'string', 'label' => 'Préfixe avoir / retour client',     'display_order' => 1,  'description' => 'Format : RET-00001'],
            ['group' => 'numbering', 'key' => 'supplier_invoice_prefix',    'value' => 'ACH',   'type' => 'string', 'label' => 'Préfixe achat fournisseur',         'display_order' => 2,  'description' => 'Format : ACH-00001'],
            ['group' => 'numbering', 'key' => 'expense_prefix',             'value' => 'DEP',   'type' => 'string', 'label' => 'Préfixe dépense',                   'display_order' => 3,  'description' => 'Format : DEP-00001'],
            ['group' => 'numbering', 'key' => 'fuel_prefix',                'value' => 'GAS',   'type' => 'string', 'label' => 'Préfixe achat carburant',           'display_order' => 4,  'description' => 'Format : GAS-00001'],
            ['group' => 'numbering', 'key' => 'stock_adjustment_prefix',    'value' => 'AJ',    'type' => 'string', 'label' => 'Préfixe ajustement de stock',       'display_order' => 5,  'description' => 'Format : AJ-00001'],
            ['group' => 'numbering', 'key' => 'slaughter_prefix',           'value' => 'ABA',   'type' => 'string', 'label' => 'Préfixe ordre d\'abattage',         'display_order' => 6,  'description' => 'Format : ABA-2026-000001'],
            ['group' => 'numbering', 'key' => 'transformation_prefix',      'value' => 'TRANS', 'type' => 'string', 'label' => 'Préfixe transformation abattoir',   'display_order' => 7,  'description' => 'Format : TRANS-2026-000001'],
            ['group' => 'numbering', 'key' => 'crop_transformation_prefix', 'value' => 'TRV',   'type' => 'string', 'label' => 'Préfixe transformation agricole',   'display_order' => 8,  'description' => 'Format : TRV-2026-000001'],
            ['group' => 'numbering', 'key' => 'mill_prefix',                'value' => 'OP',    'type' => 'string', 'label' => 'Préfixe ordre de production (provenderie)', 'display_order' => 9, 'description' => 'Format : OP-2026-000001'],
        ];

        foreach ($settings as $s) {
            $exists = DB::table('settings')
                ->where('group', $s['group'])
                ->where('key', $s['key'])
                ->whereNull('farm_id')
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert(array_merge([
                    'options'      => null,
                    'description'  => null,
                    'is_sensitive' => false,
                    'farm_id'      => null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ], $s));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->where('group', 'numbering')->delete();
    }
};
