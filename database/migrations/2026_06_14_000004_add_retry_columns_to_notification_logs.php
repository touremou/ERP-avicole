<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes nécessaires pour réessayer l'envoi des notifications WhatsApp en
 * échec (coupures réseau fréquentes en zone rurale) :
 * - recipient_phone : numéro destinataire, requis pour rejouer l'envoi.
 * - attempts        : nombre de tentatives déjà effectuées (plafonné par la
 *                      commande avismart:retry-failed-notifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('recipient_phone', 20)->nullable()->after('message');
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn(['recipient_phone', 'attempts']);
        });
    }
};
