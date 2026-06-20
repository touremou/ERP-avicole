<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit la colonne `batches.type` de ENUM vers VARCHAR.
 *
 * Historique : 2026_03_21_094350 avait figé `type` en
 * ENUM('poussiniere','chair','ponte','reproducteur'). La migration de
 * consolidation (2026_05_18) voulait la repasser en string mais sous
 * condition `if (! Schema::hasColumn('batches','type'))` : la colonne
 * existant déjà, la conversion était ignorée sur les bases déjà migrées.
 *
 * Résultat : en MySQL, l'insertion d'un lot avec un slug multi-espèces
 * (laitiere, engraissement, viande…) provenant de la table production_types
 * échouait avec « Data truncated for column 'type' ». Le type d'un lot est
 * désormais un slug dynamique (ProductionType) : il doit être un VARCHAR libre.
 *
 * SQLite n'a pas d'ENUM natif (la colonne y est déjà un varchar) : no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('batches', 'type')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `batches` MODIFY `type` VARCHAR(50) NOT NULL DEFAULT 'chair'");
        }
    }

    public function down(): void
    {
        // Irréversible en toute sécurité : restaurer l'ENUM tronquerait les lots
        // dont le type est un slug multi-espèces. On laisse la colonne en VARCHAR.
    }
};
