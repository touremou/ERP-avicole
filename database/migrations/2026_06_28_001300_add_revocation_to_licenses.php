<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vérification en ligne hybride : trace la dernière synchronisation avec le
 * serveur de licence et l'éventuelle révocation à distance (client ne payant
 * plus, fraude détectée…). Une licence révoquée bloque comme une licence
 * expirée, même si sa date de fin est dans le futur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('last_seen_at');
            $table->timestamp('last_online_check_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['revoked_at', 'last_online_check_at']);
        });
    }
};
