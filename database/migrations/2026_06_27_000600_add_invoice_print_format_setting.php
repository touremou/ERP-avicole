<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Format d'impression des ventes (BL / factures) : A4 classique ou ticket
 * thermique 80 mm. Choix par défaut configurable ; surchargeable par impression
 * via le paramètre d'URL ?format=.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $exists = DB::table('settings')->where('group', 'ventes')->where('key', 'print_format')->whereNull('farm_id')->exists();
        if (! $exists) {
            DB::table('settings')->insert([
                'group' => 'ventes', 'key' => 'print_format', 'value' => 'a4',
                'type' => 'select', 'label' => 'Format d\'impression des ventes',
                'options' => 'a4,thermal', 'display_order' => 6, 'is_sensitive' => false,
                'farm_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('group', 'ventes')->where('key', 'print_format')->delete();
        }
    }
};
