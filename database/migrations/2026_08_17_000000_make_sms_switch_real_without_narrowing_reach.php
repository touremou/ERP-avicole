<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'INTERRUPTEUR SMS DEVIENT RÉEL — SANS RIEN COUPER.
 *
 * La case « SMS » de Notifications › Préférences était enregistrée, validée,
 * persistée… et lue par personne. La décocher n'arrêtait rien, la cocher
 * n'ajoutait rien : `IndustrialAlert::via()` ajoutait le canal SMS dès qu'une
 * alerte était de priorité haute, sans consulter la moindre préférence.
 *
 * Faire lire cette case suffisait à corriger le défaut — mais aurait COUPÉ le
 * SMS pour tout le monde, la colonne valant `false` par défaut depuis sa
 * création et n'ayant jamais figuré parmi les valeurs livrées. On aurait donc
 * réduit la portée des alertes en croyant réparer un réglage : exactement
 * l'inverse du besoin d'une exploitation dont le WhatsApp ne part pas.
 *
 * Cette migration aligne donc l'état enregistré sur le comportement RÉEL
 * d'avant : le SMS partait pour tous les administrateurs sur les alertes de
 * priorité haute, donc la case doit valoir « activé » — pour les lignes
 * existantes comme pour les futures.
 *
 * Résultat : aucune alerte de moins qu'hier, et un bouton d'arrêt qui fonctionne
 * enfin. Le SMS reste borné aux alertes de priorité haute ; l'étendre aux autres
 * familles serait une décision de dépense, qui appartient à l'exploitant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')
            || ! Schema::hasColumn('notification_preferences', 'channel_sms')) {
            return;
        }

        // 1. Les comptes existants : on rétablit ce qui se passait réellement.
        //    Personne n'a jamais pu « choisir » de désactiver le SMS puisque la
        //    case ne gouvernait rien — il n'y a donc aucun choix à préserver ici.
        DB::table('notification_preferences')
            ->where('channel_sms', false)
            ->update(['channel_sms' => true, 'updated_at' => now()]);

        // 2. Les comptes futurs : le défaut de la colonne suit.
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('channel_sms')->default(true)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_preferences')
            || ! Schema::hasColumn('notification_preferences', 'channel_sms')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('channel_sms')->default(false)->change();
        });

        // On ne remet PAS les valeurs à false : ce serait couper le canal de
        // repli des comptes qui l'ont, cette fois, réellement choisi.
    }
};
