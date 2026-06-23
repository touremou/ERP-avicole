<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aligne le vocabulaire sur « carburant » (terme générique) plutôt que
     * « gasoil » (un type de carburant parmi d'autres). Met à jour les libellés
     * des paramètres existants ; idempotent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->where('key', 'fuel_price_liter')
            ->update(['label' => 'Prix carburant']);

        DB::table('settings')->where('key', 'autonomy_alert_hours')
            ->update(['label' => 'Seuil alerte autonomie carburant']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->where('key', 'fuel_price_liter')
            ->update(['label' => 'Prix gasoil']);

        DB::table('settings')->where('key', 'autonomy_alert_hours')
            ->update(['label' => 'Seuil alerte autonomie gasoil']);
    }
};
