<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CANAL PUSH dans les préférences d'alerte.
 *
 * Il s'ajoute aux canaux existants (cloche in-app, e-mail, WhatsApp) pour que la
 * décision d'éligibilité reste au même endroit : NotificationHub choisit les
 * canaux d'un destinataire, AlertNotification les porte.
 *
 * ACTIVÉ PAR DÉFAUT — mais sans effet tant que l'appareil ne s'est pas abonné.
 * C'est le navigateur qui demande l'autorisation, l'utilisateur qui l'accorde, et
 * seulement alors qu'un abonnement existe. Le réglage sert donc à COUPER le push
 * pour quelqu'un qui l'aurait accordé puis regretté, sans qu'il ait à retrouver
 * les paramètres de son navigateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('channel_push')->default(true)->after('channel_email');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('channel_push');
        });
    }
};
