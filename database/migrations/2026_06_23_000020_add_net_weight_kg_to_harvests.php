<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le poids net pesé (toujours en kg) sur les récoltes.
 *
 * Découple le POIDS AGRONOMIQUE (net_weight_kg, toujours en kg) de l'UNITÉ
 * COMMERCIALE (quantity + unit, qui peut être caisses, sacs, bottes…). Les KPI
 * de rendement (total récolté, kg/ha, écart vs attendu) s'appuient désormais
 * sur ce poids, et restent justes même quand la récolte est saisie dans une
 * autre unité que le kg.
 *
 * Reprise des données existantes : pour les récoltes déjà saisies en kg, on
 * recopie `quantity` dans `net_weight_kg` afin de ne pas perdre l'historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvests', function (Blueprint $table) {
            // Poids net pesé après récolte (kg). Null = non pesé.
            $table->decimal('net_weight_kg', 12, 3)->nullable()->after('unit');
        });

        // Backfill : les récoltes historiques en kg ont leur quantité = poids net.
        DB::table('harvests')
            ->where('unit', 'kg')
            ->whereNull('net_weight_kg')
            ->update(['net_weight_kg' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('harvests', function (Blueprint $table) {
            $table->dropColumn('net_weight_kg');
        });
    }
};
