<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADRESSE DESTINATAIRE — le pendant de `recipient_phone` pour le canal e-mail.
 *
 * `notification_logs` traçait les envois WhatsApp et SMS, jamais les e-mails :
 * l'écran « Historique des notifications » ne pouvait donc pas dire si un
 * message était parti par mail, ni pourquoi il n'était pas parti. Sans colonne
 * d'adresse, un journal d'e-mails ne dirait pas non plus À QUI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('recipient_email')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn('recipient_email');
        });
    }
};
