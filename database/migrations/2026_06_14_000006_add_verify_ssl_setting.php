<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vérification SSL des appels providers WhatsApp.
 *
 * Active par défaut (sécurisé). Le bundle CA (composer/ca-bundle) règle déjà
 * la cause #1 d'échec « cURL error 60 » sur PHP mal configuré. Ce réglage
 * n'est qu'un dernier recours pour les environnements où aucun bundle n'est
 * exploitable — à laisser activé en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'whatsapp', 'key' => 'verify_ssl', 'farm_id' => null],
            [
                'value'         => '1',
                'type'          => 'boolean',
                'label'         => 'Vérifier le certificat SSL',
                'description'   => "Garder activé. Désactiver uniquement en dernier recours si l'envoi échoue avec « cURL error 60 » sur un serveur mal configuré (déconseillé : non sécurisé).",
                'unit'          => null,
                'display_order' => 11,
                'is_sensitive'  => false,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'whatsapp')
            ->where('key', 'verify_ssl')
            ->whereNull('farm_id')
            ->delete();
    }
};
