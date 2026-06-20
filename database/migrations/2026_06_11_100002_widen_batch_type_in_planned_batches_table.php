<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit `planned_batches.batch_type` de ENUM vers VARCHAR.
 *
 * Même classe de problème que batches.type : la colonne était figée en
 * ENUM('chair','ponte','reproducteur') alors que la couche applicative
 * (PlanningController) valide désormais batch_type comme un slug libre
 * multi-espèces (required|string|max:50) issu des types de production.
 *
 * Sans cette correction, planifier une bande non-volaille (laitiere,
 * engraissement…) échoue en MySQL avec « Data truncated for column
 * 'batch_type' ». SQLite n'a pas d'ENUM natif : no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('planned_batches', 'batch_type')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `planned_batches` MODIFY `batch_type` VARCHAR(50) NOT NULL");
        }
    }

    public function down(): void
    {
        // Irréversible en toute sécurité : restaurer l'ENUM tronquerait les
        // bandes planifiées dont le type est un slug multi-espèces.
    }
};
