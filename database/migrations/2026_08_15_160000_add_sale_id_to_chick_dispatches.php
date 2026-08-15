<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien entre un dispatch de poussins VENDUS et la vente correspondante.
 *
 * Le couvoir enregistrait la vente de poussins chez lui — client, prix
 * unitaire, montant total — et n'en disait rien au module commercial : aucune
 * vente, donc aucune créance, aucune ligne au journal, aucune recette. Cette
 * colonne matérialise le lien qui manquait, et sert aussi de garde d'idempotence
 * (un dispatch déjà rattaché ne recrée pas de vente).
 *
 * Nullable : les trois autres destinations (élevage, stock, perte) n'ont pas de
 * vente, et les dispatchs déjà enregistrés n'en auront pas rétroactivement —
 * inventer des ventes anciennes fausserait un exercice clos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chick_dispatches') || Schema::hasColumn('chick_dispatches', 'sale_id')) {
            return;
        }

        Schema::table('chick_dispatches', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('client_id')
                ->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chick_dispatches') || ! Schema::hasColumn('chick_dispatches', 'sale_id')) {
            return;
        }

        Schema::table('chick_dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
