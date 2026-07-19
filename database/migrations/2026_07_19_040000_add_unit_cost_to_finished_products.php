<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valorisation des coûts (lot B) — coût de revient AU KG des produits finis.
 *
 * Jusqu'ici tout produit fini entrait en stock à unit_price 0 : le coût vif
 * (achat ou lot interne) s'arrêtait à la marge de l'ordre, sans jamais
 * descendre au kg. On ajoute `unit_cost` (coût de revient/kg), maintenu en
 * COÛT MOYEN PONDÉRÉ à chaque entrée, et propagé carcasse → découpes →
 * transformés (+ production_cost). Permet une marge réelle par gamme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finished_products', function (Blueprint $t) {
            $t->decimal('unit_cost', 12, 2)->default(0)->after('unit_price'); // coût de revient / kg (CMUP)
        });
    }

    public function down(): void
    {
        Schema::table('finished_products', function (Blueprint $t) {
            $t->dropColumn('unit_cost');
        });
    }
};
