<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L'ORDRE D'ABATTAGE PORTE LE COÛT DES ANIMAUX QU'IL PREND.
 *
 * Le coût matière se recalculait à chaque lecture — entrée en stock, découpe,
 * dossier de lot — sur le seul prix d'achat des sujets. Il porte désormais le
 * coût de revient complet du lot (acquisition + élevage), qui bouge tant que le
 * lot vit : il se FIGE à l'abattage, comme le coût de l'aliment au pointage.
 * Null pour un ordre exécuté avant : ses lecteurs gardent l'ancien calcul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $table) {
            $table->decimal('material_cost', 15, 2)->nullable()->after('total_live_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $table) {
            $table->dropColumn('material_cost');
        });
    }
};
