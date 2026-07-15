<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi de péremption des consommables (vaccins, médicaments, désinfectants,
 * intrants…). Approche par article : date d'expiration + n° de lot optionnels.
 * Les articles non périssables laissent ces champs nuls et ne déclenchent
 * aucune alerte. (Le suivi multi-lots FEFO pourra être ajouté ultérieurement.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stocks') && ! Schema::hasColumn('stocks', 'expiry_date')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('alert_threshold');
                $table->string('lot_number')->nullable()->after('expiry_date');
                $table->index('expiry_date');
            });
        }

        // Fenêtre d'alerte de péremption (jours avant expiration).
        if (Schema::hasTable('settings')) {
            $exists = DB::table('settings')->where('group', 'stocks')->where('key', 'expiry_alert_days')->whereNull('farm_id')->exists();
            if (! $exists) {
                DB::table('settings')->insert([
                    'group' => 'stocks', 'key' => 'expiry_alert_days', 'value' => '30',
                    'type' => 'number', 'label' => 'Alerte péremption (jours avant)',
                    'unit' => 'jours', 'display_order' => 3, 'is_sensitive' => false,
                    'farm_id' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stocks') && Schema::hasColumn('stocks', 'expiry_date')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->dropIndex(['expiry_date']);
                $table->dropColumn(['expiry_date', 'lot_number']);
            });
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('group', 'stocks')->where('key', 'expiry_alert_days')->delete();
        }
    }
};
