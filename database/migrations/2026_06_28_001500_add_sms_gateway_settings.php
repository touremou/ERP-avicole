<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Réglages de la passerelle SMS (groupe « sms »), consommés par SmsService.
 * driver 'log' = aucun envoi réel (dev) ; 'http' = POST vers api_url.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'driver',  'value' => 'log', 'type' => 'select',   'label' => 'Driver SMS',            'options' => 'log,http', 'display_order' => 1],
            ['key' => 'api_url', 'value' => '',    'type' => 'string',   'label' => 'URL de la passerelle',  'description' => 'Endpoint POST de l\'opérateur / gateway GSM', 'display_order' => 2],
            ['key' => 'api_key', 'value' => '',    'type' => 'password', 'label' => 'Clé API SMS',           'is_sensitive' => true, 'display_order' => 3],
            ['key' => 'sender',  'value' => 'AVISMART', 'type' => 'string', 'label' => 'Expéditeur (sender)', 'description' => 'Nom affiché comme émetteur du SMS', 'display_order' => 4],
        ];

        foreach ($rows as $r) {
            $exists = DB::table('settings')->where('group', 'sms')->where('key', $r['key'])->whereNull('farm_id')->exists();
            if ($exists) {
                continue;
            }

            DB::table('settings')->insert(array_merge([
                'group'        => 'sms',
                'options'      => null,
                'description'  => null,
                'is_sensitive' => false,
                'farm_id'      => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ], $r));
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'sms')->whereIn('key', ['driver', 'api_url', 'api_key', 'sender'])->delete();
    }
};
