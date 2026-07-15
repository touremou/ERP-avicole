<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mortalité EN INFIRMERIE (traçabilité des sujets isolés).
 *
 * Trou métier : un sujet mis en infirmerie (qty_quarantine_in) est DÉJÀ
 * décompté de current_quantity. S'il meurt, le déclarer dans `mortality`
 * le décomptait DEUX FOIS ; ne pas le déclarer sous-estimait la mortalité
 * du lot et gonflait le solde d'infirmerie à jamais.
 *
 * `mortality_infirmary` : morts PARMI les isolés — AUCUN impact sur
 * current_quantity (déjà sortis de l'effectif), mais entre dans la
 * mortalité totale du lot (Batch::total_mortality) et décrémente le solde
 * d'infirmerie (Batch::infirmary_count = Σ in − Σ out − Σ morts inf.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            $table->unsignedInteger('mortality_infirmary')->default(0)->after('qty_quarantine_out');
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            $table->dropColumn('mortality_infirmary');
        });
    }
};
