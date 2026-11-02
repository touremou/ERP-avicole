<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seuil d'alerte de l'AUTONOMIE FOURRAGÈRE du troupeau de ruminants, éditable
 * dans Paramètres → Stocks. Décision de l'exploitation : alerte sous 15 jours
 * (Stock::seuilAutonomieFourragere). Une valeur déjà réglée est respectée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('settings')
            ->where('group', 'stocks')->where('key', 'forage_autonomy_alert_days')->exists();

        if (! $existe) {
            DB::table('settings')->insert([
                'group' => 'stocks', 'key' => 'forage_autonomy_alert_days', 'value' => '15',
                'type' => 'number', 'label' => "Alerte d'autonomie fourragère des ruminants (sous N jours, en matière sèche)",
                'unit' => 'jours', 'display_order' => 62,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'stocks')->where('key', 'forage_autonomy_alert_days')->delete();
        Setting::clearCache();
    }
};
