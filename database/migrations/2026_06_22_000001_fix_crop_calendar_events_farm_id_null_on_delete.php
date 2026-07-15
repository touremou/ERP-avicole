<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crop_calendar_events.farm_id était NOT NULL avec une FK ON DELETE SET NULL.
 * MySQL refuse un SET NULL sur une colonne NOT NULL (erreur 1830) — SQLite,
 * lui, ignore la clause. On rend donc la colonne nullable AVANT de poser la
 * FK nullOnDelete, pour la parité prod (MySQL 8).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('crop_calendar_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite n'applique pas les FK ON DELETE : il suffit de rendre
            // la colonne nullable (change() reconstruit la table).
            Schema::table('crop_calendar_events', function (Blueprint $table) {
                $table->unsignedBigInteger('farm_id')->nullable()->change();
            });
            return;
        }

        // MySQL/MariaDB : retirer la FK, rendre la colonne nullable, restaurer
        // la FK en SET NULL (désormais compatible avec une colonne nullable).
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
        });
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->unsignedBigInteger('farm_id')->nullable()->change();
        });
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->foreign('farm_id')->references('id')->on('farms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crop_calendar_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('crop_calendar_events', function (Blueprint $table) {
                $table->unsignedBigInteger('farm_id')->nullable(false)->change();
            });
            return;
        }

        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
        });
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->unsignedBigInteger('farm_id')->nullable(false)->change();
        });
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->foreign('farm_id')->references('id')->on('farms')->cascadeOnDelete();
        });
    }
};
