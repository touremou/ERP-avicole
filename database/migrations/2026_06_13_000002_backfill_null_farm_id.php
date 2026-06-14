<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattache à la ferme par défaut tous les enregistrements métier dont le
     * farm_id est resté NULL. Ces orphelins apparaissent quand un modèle est
     * créé hors contexte HTTP (seeder, factory, console) ou avant que la ferme
     * courante ne soit définie en session : le décompte par ferme de l'écran
     * Multi-Sites filtre strictement sur farm_id et ignore donc ces lignes,
     * d'où des cartes affichant 0 sujet / 0 lot / 0 bâtiment alors que des
     * données existent. La correction du trait BelongsToFarm empêche les
     * nouveaux orphelins ; cette migration répare l'existant.
     */
    public function up(): void
    {
        if (! Schema::hasTable('farms')) return;

        // Première ferme active (généralement « Ferme Principale »), cohérent
        // avec Farm::defaultId().
        $farmId = DB::table('farms')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->value('id');

        if (! $farmId) return;

        $tables = [
            'buildings', 'batches', 'daily_checks',
            'stocks', 'stock_movements',
            'employees', 'providers',
            'egg_productions', 'incubations',
            'sales', 'sale_items', 'payments', 'clients', 'price_lists',
            'dispatches', 'dispatch_items', 'receptions', 'reception_items', 'discrepancy_reports',
            'water_sources', 'water_readings', 'energy_sources', 'energy_readings', 'fuel_purchases',
            'slaughter_orders', 'slaughter_results', 'cutting_sessions', 'cut_products',
            'finished_products', 'transformations',
            'planned_batches',
            'raw_materials', 'formulas', 'mill_productions', 'mill_machines',
        ];

        foreach ($tables as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'farm_id')) {
                DB::table($t)->whereNull('farm_id')->update(['farm_id' => $farmId]);
            }
        }
    }

    public function down(): void
    {
        // Rattachement de données : non réversible sans perte d'information
        // (impossible de distinguer les orphelins d'origine). No-op.
    }
};
