<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La couveuse peut être EN PANNE.
 *
 * La fiche de la couveuse propose « HORS SERVICE (PANNE) », la liste la colore
 * en rouge et la compte « à entretenir » — mais l'ENUM de la colonne ne
 * connaissait que Disponible / Occupé / Maintenance : en production (MySQL
 * strict), enregistrer une panne échouait. Constaté pendant l'audit des
 * listes : la mise à couver doit refuser une couveuse en panne, encore
 * faut-il pouvoir la déclarer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incubators')) return;

        // SQLite n'a pas de type ENUM natif (pas de contrainte à étendre).
        if (DB::connection()->getDriverName() === 'sqlite') return;

        DB::statement("ALTER TABLE incubators MODIFY COLUMN status ENUM('Disponible','Occupé','Maintenance','Panne') NOT NULL DEFAULT 'Disponible'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('incubators')) return;
        if (DB::connection()->getDriverName() === 'sqlite') return;

        DB::table('incubators')->where('status', 'Panne')->update(['status' => 'Maintenance']);
        DB::statement("ALTER TABLE incubators MODIFY COLUMN status ENUM('Disponible','Occupé','Maintenance') NOT NULL DEFAULT 'Disponible'");
    }
};
