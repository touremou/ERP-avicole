<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Heures ouvrées de la ferme — pilotage anti-fraude.
 *
 * Toute vente validée ou tout encaissement enregistré HORS de cette plage est
 * escaladé en alerte critique (atteint le numéro admin de secours), car
 * l'activité hors horaires est un signal classique de détournement.
 *
 * Plage vide (start ou end non renseigné) = détection désactivée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'group'         => 'whatsapp',
                'key'           => 'business_hours_start',
                'value'         => '06:00',
                'type'          => 'string',
                'label'         => 'Début heures ouvrées',
                'description'   => "Activité (vente/encaissement) avant cette heure = alerte critique. Vide = désactivé.",
                'unit'          => 'HH:MM',
                'display_order' => 8,
                'is_sensitive'  => false,
            ],
            [
                'group'         => 'whatsapp',
                'key'           => 'business_hours_end',
                'value'         => '20:00',
                'type'          => 'string',
                'label'         => 'Fin heures ouvrées',
                'description'   => "Activité (vente/encaissement) après cette heure = alerte critique. Vide = désactivé.",
                'unit'          => 'HH:MM',
                'display_order' => 9,
                'is_sensitive'  => false,
            ],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->updateOrInsert(
                ['group' => $s['group'], 'key' => $s['key'], 'farm_id' => null],
                array_merge($s, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'whatsapp')
            ->whereIn('key', ['business_hours_start', 'business_hours_end'])
            ->whereNull('farm_id')
            ->delete();
    }
};
