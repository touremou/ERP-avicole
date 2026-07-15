<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Format du code-barres linéaire imprimé sur les étiquettes, quand le
 * « Type de code » (etiquettes.symbology) vaut « barcode » ou « both » :
 *
 *   code128 — dense, tout l'ASCII (défaut, meilleur usage général) ;
 *   code39  — alphanumérique majuscule, auto-vérifiant, lu par tous les
 *             scanners bas de gamme (robuste en contexte pilote) ;
 *   ean13   — standard retail/caisse, 13 chiffres. Repli AUTOMATIQUE sur
 *             Code128 si le code n'est pas numérique 12/13 (les codes de
 *             lot type « P-001 » ne sont pas EAN — jamais d'étiquette vide).
 *
 * Sans effet si symbology = qr.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $exists = DB::table('settings')->where('group', 'etiquettes')->where('key', 'barcode_format')->whereNull('farm_id')->exists();
        if ($exists) return;

        DB::table('settings')->insert([
            'group' => 'etiquettes', 'key' => 'barcode_format', 'value' => 'code128', 'type' => 'select',
            'label' => 'Format de code-barres', 'options' => 'code128,code39,ean13', 'display_order' => 1,
            'description' => "Utilisé si « Type de code » = code-barres. EAN-13 : codes 13 chiffres, sinon repli Code128.",
            'is_sensitive' => false, 'farm_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;
        DB::table('settings')->where('group', 'etiquettes')->where('key', 'barcode_format')->delete();
    }
};
