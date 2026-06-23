<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mill_machines')) return;

        // SQLite n'a pas de type ENUM natif (pas de contrainte à étendre).
        if (DB::connection()->getDriverName() === 'sqlite') return;

        DB::statement("ALTER TABLE mill_machines MODIFY COLUMN status ENUM('Opérationnel','Maintenance','En Panne','Désactivé') NOT NULL DEFAULT 'Opérationnel'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('mill_machines')) return;
        if (DB::connection()->getDriverName() === 'sqlite') return;

        // Ramène les 'Désactivé' à 'En Panne' avant de rétrécir l'ENUM.
        DB::table('mill_machines')->where('status', 'Désactivé')->update(['status' => 'En Panne']);
        DB::statement("ALTER TABLE mill_machines MODIFY COLUMN status ENUM('Opérationnel','Maintenance','En Panne') NOT NULL DEFAULT 'Opérationnel'");
    }
};
