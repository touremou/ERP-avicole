<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Type de code imprimé sur les étiquettes : QR (traçabilité par URL),
 * code-barres Code128 (intégration scanners / retail), ou les deux.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $exists = DB::table('settings')->where('group', 'etiquettes')->where('key', 'symbology')->whereNull('farm_id')->exists();
        if ($exists) return;

        DB::table('settings')->insert([
            'group' => 'etiquettes', 'key' => 'symbology', 'value' => 'qr', 'type' => 'select',
            'label' => 'Type de code', 'options' => 'qr,barcode,both', 'display_order' => 0,
            'description' => 'QR (traçabilité), code-barres Code128 (scanners), ou les deux.',
            'is_sensitive' => false, 'farm_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;
        DB::table('settings')->where('group', 'etiquettes')->where('key', 'symbology')->delete();
    }
};
