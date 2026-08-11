<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LE DRIVER TWILIO NE POUVAIT PAS ÊTRE CONFIGURÉ DEPUIS L'APPLICATION.
 *
 * `WhatsAppService::sendViaTwilio()` lisait ses identifiants dans deux variables
 * d'environnement (`TWILIO_SID`, `TWILIO_TOKEN`) — inaccessibles à un exploitant
 * sur hébergement mutualisé, et absentes de la documentation. Or l'écran
 * Paramètres › WhatsApp AFFICHE « Clé API » et « URL API » dès qu'on sélectionne
 * twilio : on les renseignait, et le driver appelait Twilio avec une
 * authentification vide. 401 à chaque envoi, sur une configuration que l'écran
 * présentait comme faite.
 *
 * Le service lit désormais la clé applicative, au format « SID:TOKEN » — les deux
 * moitiés d'une authentification basique dans le champ unique que l'écran offre.
 * Cette migration l'écrit noir sur blanc dans la description du réglage : une
 * convention que seul le code connaît n'est pas une convention.
 *
 * Elle déclare aussi le NUMÉRO EXPÉDITEUR, qui n'avait aucun champ. Sans lui, le
 * driver retombait sur le numéro de bac à sable de Twilio — lequel n'écrit qu'aux
 * numéros pré-autorisés, ce qui produit un échec de plus, tout aussi muet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Numéro expéditeur — exigé par Twilio, jamais réglable jusqu'ici.
        $exists = DB::table('settings')
            ->where('group', 'whatsapp')->where('key', 'sender')->whereNull('farm_id')->exists();

        if (! $exists) {
            DB::table('settings')->insert([
                'group'         => 'whatsapp',
                'key'           => 'sender',
                'value'         => '',
                'type'          => 'string',
                'label'         => 'Numéro expéditeur (driver Twilio)',
                'description'   => 'Numéro WhatsApp validé par Twilio, ex. +14155238886. '
                                 . 'Laissé vide, le bac à sable de Twilio est utilisé : il '
                                 . 'n’écrit qu’aux numéros pré-autorisés.',
                'display_order' => 4,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        // 2. La convention de la clé API, écrite là où on la saisit.
        DB::table('settings')
            ->where('group', 'whatsapp')->where('key', 'api_key')->whereNull('farm_id')
            ->update([
                'description' => 'Driver Twilio : saisir « SID:TOKEN » (les deux séparés par '
                               . 'deux-points). Autres drivers : la clé fournie par le '
                               . 'provider.',
                'updated_at'  => $now,
            ]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'whatsapp')->where('key', 'sender')->whereNull('farm_id')->delete();
    }
};
