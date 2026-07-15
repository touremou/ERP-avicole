<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ticket de caisse POS configurable (groupe « ventes »).
 *
 * - ticket_enabled   : émettre (ou non) un reçu après chaque encaissement POS.
 * - ticket_autoprint : déclencher l'impression automatiquement à l'ouverture.
 * - ticket_footer    : message de remerciement personnalisable.
 * - ticket_note      : seconde ligne de pied (consigne) — vide = masquée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'ticket_enabled',   'value' => '1', 'type' => 'boolean',  'label' => 'Émettre un ticket de caisse',        'description' => 'Affiche le reçu 80 mm après chaque encaissement POS', 'display_order' => 6],
            ['key' => 'ticket_autoprint', 'value' => '1', 'type' => 'boolean',  'label' => 'Impression automatique du ticket',   'description' => "Ouvre la boîte d'impression à l'ouverture du reçu",    'display_order' => 7],
            ['key' => 'ticket_footer',    'value' => 'Merci de votre achat !', 'type' => 'textarea', 'label' => 'Message de pied de ticket', 'description' => 'Ligne de remerciement en bas du reçu', 'display_order' => 8],
            ['key' => 'ticket_note',      'value' => 'Conservez ce reçu pour tout échange ou retour.', 'type' => 'textarea', 'label' => 'Consigne / note du ticket', 'description' => 'Seconde ligne de pied (laisser vide pour masquer)', 'display_order' => 9],
        ];

        foreach ($rows as $r) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'ventes', 'key' => $r['key']],
                [
                    'value'         => $r['value'],
                    'type'          => $r['type'],
                    'label'         => $r['label'],
                    'description'   => $r['description'],
                    'display_order' => $r['display_order'],
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'ventes')
            ->whereIn('key', ['ticket_enabled', 'ticket_autoprint', 'ticket_footer', 'ticket_note'])
            ->delete();
    }
};
