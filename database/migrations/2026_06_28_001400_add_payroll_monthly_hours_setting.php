<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Durée mensuelle contractuelle (base du taux horaire pour les heures
 * supplémentaires). Défaut 208 h (26 j × 8 h) ; paramétrable selon la
 * convention applicable. Consommé par PayrollController::recordOvertime().
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('group', 'rh')->where('key', 'monthly_hours')->whereNull('farm_id')->exists();
        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'group'         => 'rh',
            'key'           => 'monthly_hours',
            'value'         => '208',
            'type'          => 'number',
            'label'         => 'Durée mensuelle contractuelle (base heures sup.)',
            'unit'          => 'h',
            'display_order' => 6,
            'options'       => null,
            'is_sensitive'  => false,
            'farm_id'       => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'rh')->where('key', 'monthly_hours')->delete();
    }
};
