<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coordonnées fournisseur affichées sur l'écran de renouvellement d'abonnement
 * (groupe « licence »). Surchargent les valeurs de config/license.php.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'vendor_name',    'value' => 'TechCenter',  'type' => 'string',   'label' => 'Nom du fournisseur',       'description' => 'Affiché sur l\'écran de renouvellement de licence', 'display_order' => 1],
            ['key' => 'vendor_address', 'value' => '',            'type' => 'string',   'label' => 'Adresse du fournisseur',   'description' => 'Ex : Bvd du 30 Août, après la station CAP', 'display_order' => 2],
            ['key' => 'vendor_phone',   'value' => '',            'type' => 'string',   'label' => 'Téléphone du fournisseur', 'description' => 'Numéro à contacter pour renouveler l\'abonnement', 'display_order' => 3],
        ];

        foreach ($rows as $r) {
            $exists = DB::table('settings')->where('group', 'licence')->where('key', $r['key'])->whereNull('farm_id')->exists();
            if ($exists) {
                continue;
            }

            DB::table('settings')->insert(array_merge($r, [
                'group'        => 'licence',
                'options'      => null,
                'is_sensitive' => false,
                'farm_id'      => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'licence')
            ->whereIn('key', ['vendor_name', 'vendor_address', 'vendor_phone'])
            ->delete();
    }
};
