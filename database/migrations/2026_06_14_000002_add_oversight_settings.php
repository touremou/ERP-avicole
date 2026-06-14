<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres de pilotage / anti-fraude (visibilité du propriétaire hors site).
 *
 * - whatsapp.large_sale_threshold : montant d'une vente au-delà duquel une
 *   alerte WhatsApp « grosse vente » est escaladée en CRITIQUE (donc aussi
 *   envoyée au numéro admin de secours, même sans abonnement). 0 = désactivé.
 *
 * Les alertes d'annulation de vente validée et d'ajustement manuel de stock
 * (deux vecteurs classiques de détournement) n'ont pas de seuil : elles sont
 * toujours diffusées via le canal anti-fraude.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'group'         => 'whatsapp',
                'key'           => 'large_sale_threshold',
                'value'         => '0',
                'type'          => 'number',
                'label'         => 'Seuil alerte grosse vente',
                'description'   => "Montant d'une vente validée au-delà duquel une alerte critique est envoyée au propriétaire (0 = désactivé).",
                'unit'          => 'GNF',
                'display_order' => 7,
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
            ->whereIn('key', ['large_sale_threshold'])
            ->whereNull('farm_id')
            ->delete();
    }
};
