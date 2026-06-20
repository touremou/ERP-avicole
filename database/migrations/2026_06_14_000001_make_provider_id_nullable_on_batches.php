<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * batches.provider_id était NOT NULL : tout lot DEVAIT être rattaché à un
 * fournisseur. Or un lot éclos à la ferme (dispatch poussins d'une
 * incubation interne) n'a pas de fournisseur d'achat → l'insertion échouait
 * (« provider_id ne peut être null »).
 *
 * On rend la colonne nullable. La contrainte FK (restrictOnDelete, cf.
 * 2026_06_11_180000) est préservée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite : change() reconstruit la table en conservant les données.
            Schema::table('batches', function (Blueprint $table) {
                $table->unsignedBigInteger('provider_id')->nullable()->change();
            });
            return;
        }

        // MySQL/MariaDB : on retire la FK, on rend la colonne nullable, on
        // restaure la FF (RESTRICT, identique à l'état courant).
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->change();
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('batches', function (Blueprint $table) {
                $table->unsignedBigInteger('provider_id')->nullable(false)->change();
            });
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
        });
    }
};
