<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Heure d'envoi du digest d'activité par employé (commande
 * avismart:activity-digest). Par défaut en fin de journée ouvrée (20:00),
 * pour récapituler au propriétaire QUI a fait QUOI dans la journée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'whatsapp', 'key' => 'activity_digest_hour', 'farm_id' => null],
            [
                'value'         => '20:00',
                'type'          => 'string',
                'label'         => 'Heure du digest d\'activité',
                'description'   => "Heure d'envoi du récapitulatif d'activité par employé (ventes, encaissements, stock).",
                'unit'          => 'HH:MM',
                'display_order' => 10,
                'is_sensitive'  => false,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'whatsapp')
            ->where('key', 'activity_digest_hour')
            ->whereNull('farm_id')
            ->delete();
    }
};
