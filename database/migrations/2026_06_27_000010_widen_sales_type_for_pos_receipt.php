<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit `sales.type` (ENUM → VARCHAR) pour accueillir le type `comptant`.
 *
 * Une vente au comptoir (POS) n'est ni un bon de livraison (BL) ni une facture :
 * c'est un encaissement comptant matérialisé par un TICKET DE CAISSE. Jusqu'ici
 * le POS réutilisait `bon_livraison`, ce qui imprimait un reçu préfixé « BL- »
 * — sémantiquement faux (BL = bon de livraison, document de remise de
 * marchandise). On introduit donc un type dédié `comptant`, avec sa propre
 * numérotation configurable (préfixe « TKT » par défaut).
 *
 * MySQL : on passe la colonne en VARCHAR (l'ENUM figé refuserait `comptant`).
 * SQLite : pas d'ENUM natif (la colonne est déjà un TEXT) → no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'type')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `sales` MODIFY `type` VARCHAR(20) NOT NULL DEFAULT 'bon_livraison'");
        }
    }

    public function down(): void
    {
        // Irréversible en toute sécurité : restaurer l'ENUM tronquerait les
        // ventes comptant déjà émises.
    }
};
