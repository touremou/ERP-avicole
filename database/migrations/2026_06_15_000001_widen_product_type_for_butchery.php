<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit `cut_products.product_type` et `finished_products.product_type` de
 * ENUM (taxonomie volaille figée) vers VARCHAR.
 *
 * La découpe est désormais multiespèces : les morceaux dépendent de la famille
 * de l'espèce (gigot, aloyau, jambon, râble, filet…) et ne tiennent pas dans
 * l'ENUM volaille d'origine. En MySQL, insérer un morceau ovin/bovin échouait
 * avec « Data truncated for column 'product_type' ». Les codes de morceaux sont
 * pilotés par config/butchery.php (App\Services\ButcheryNomenclature) : la
 * colonne doit être un VARCHAR libre.
 *
 * SQLite (tests) n'a pas d'ENUM natif (la colonne y est déjà un varchar) : no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('cut_products', 'product_type')) {
            DB::statement("ALTER TABLE `cut_products` MODIFY `product_type` VARCHAR(40) NOT NULL");
        }

        if (Schema::hasColumn('finished_products', 'product_type')) {
            DB::statement("ALTER TABLE `finished_products` MODIFY `product_type` VARCHAR(40) NOT NULL");
        }
    }

    public function down(): void
    {
        // Irréversible en toute sécurité : restaurer l'ENUM tronquerait les
        // morceaux multiespèces. On laisse les colonnes en VARCHAR.
    }
};
