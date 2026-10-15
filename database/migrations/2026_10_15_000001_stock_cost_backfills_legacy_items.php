<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * LES ARTICLES ANCIENS N'AVAIENT PAS DE COÛT MOYEN.
 *
 * `last_unit_price` (le coût moyen pondéré, base de toute valorisation :
 * fiche article, tableau de bord, logistique, export) a été ajoutée avec un
 * défaut à 0. Les articles créés avant n'avaient que `unit_price`, le prix
 * saisi : ils valaient 0 partout — sauf dans l'export, qui lisait l'autre
 * colonne. Depuis, chaque création et chaque entrée écrit les deux ensemble.
 *
 * On reprend le prix saisi comme coût de départ, là seulement où le coût
 * manque. Un coût déjà tenu n'est jamais touché.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stocks')
            ->where(fn ($q) => $q->whereNull('last_unit_price')->orWhere('last_unit_price', '<=', 0))
            ->where('unit_price', '>', 0)
            ->update(['last_unit_price' => DB::raw('unit_price')]);
    }

    public function down(): void
    {
        // Irréversible sans perte : on ne sait plus quels coûts étaient vides.
    }
};
