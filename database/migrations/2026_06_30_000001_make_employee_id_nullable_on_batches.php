<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * batches.employee_id était NOT NULL : tout lot DEVAIT être rattaché à un
 * employé responsable. Or sur un palier d'abonnement n'incluant pas le module
 * Annuaire (employés/fournisseurs), une ferme ne peut créer aucun employé →
 * impossible de créer son premier lot, fonction pourtant incluse dans le palier
 * (élevage). Blocage dur du cœur du produit.
 *
 * On rend la colonne nullable : le responsable devient une métadonnée
 * OPTIONNELLE (les paliers avec Annuaire continuent de la renseigner). La FK
 * restrictOnDelete (cf. 2026_06_11_180000) est préservée. Aligne employee_id
 * sur provider_id, déjà rendu nullable (cf. 2026_06_14_000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite : change() reconstruit la table en conservant les données.
            Schema::table('batches', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_id')->nullable()->change();
            });
            return;
        }

        // MySQL/MariaDB : retirer la FK, rendre nullable, restaurer la FK (RESTRICT).
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('batches', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_id')->nullable(false)->change();
            });
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }
};
