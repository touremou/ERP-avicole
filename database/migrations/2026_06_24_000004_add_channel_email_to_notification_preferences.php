<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le canal e-mail aux préférences de notification.
 *
 * Les alertes du NotificationHub pourront ainsi être envoyées par e-mail
 * (file d'attente) en plus du WhatsApp et de l'in-app (base de données).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_preferences', 'channel_email')) {
                $table->boolean('channel_email')->default(false)->after('channel_database');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            if (Schema::hasColumn('notification_preferences', 'channel_email')) {
                $table->dropColumn('channel_email');
            }
        });
    }
};
