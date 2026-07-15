<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adresse e-mail « admin » destinataire de secours des alertes CRITIQUES.
 *
 * Pendant e-mail du filet WhatsApp existant (whatsapp.admin_phone) : sur une
 * alerte critique, NotificationHub envoie aussi un e-mail à cette adresse,
 * même si personne n'est explicitement abonné au type d'alerte. Vide = inactif.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')
            ->where('group', 'whatsapp')->where('key', 'admin_email')->whereNull('farm_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'group'         => 'whatsapp',
            'key'           => 'admin_email',
            'value'         => '',
            'type'          => 'string',
            'label'         => 'E-mail admin (alertes critiques)',
            'description'   => 'Destinataire de secours des alertes critiques par e-mail. Laisser vide pour désactiver.',
            'options'       => null,
            'unit'          => null,
            'display_order' => 7,
            'is_sensitive'  => false,
            'farm_id'       => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        \App\Models\Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'whatsapp')->where('key', 'admin_email')->whereNull('farm_id')->delete();
        \App\Models\Setting::clearCache();
    }
};
