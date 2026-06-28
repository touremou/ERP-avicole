<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arrondi de caisse (ventes.cash_rounding).
 *
 * En Guinée, certaines petites coupures ne circulent plus : impossible de
 * rendre la monnaie au franc près. Ce réglage arrondit le total payable d'une
 * vente à la coupure la plus proche (0 = désactivé, 100, 500, 1000, 2000 GNF).
 * Consommé par le helper cash_round() et Sale::recalculateTotals().
 *
 * Par défaut 0 (désactivé) pour ne pas modifier le comportement existant.
 * Idempotent : on n'insère que si la clé est absente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')
            ->where('group', 'ventes')
            ->where('key', 'cash_rounding')
            ->whereNull('farm_id')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('settings')->insert([
            'group'         => 'ventes',
            'key'           => 'cash_rounding',
            'value'         => '0',
            'type'          => 'select',
            'label'         => 'Arrondi de caisse (coupure GNF)',
            'display_order' => 10,
            'options'       => '0,100,500,1000,2000',
            'description'   => 'Arrondit le total payable à la coupure la plus proche (0 = désactivé). Évite les écarts de monnaie : un total de 55 100 sur coupures de 1 000 devient 55 000.',
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
            ->where('key', 'cash_rounding')
            ->delete();
    }
};
