<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ═══ 1. TABLE DES FERMES ═══
        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // "Ferme Avicole de Dubréka"
            $table->string('code', 20)->unique();                   // "DUB", "CON", "KIN"
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();                    // Conakry, Kindia, Dubréka...
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('manager_name')->nullable();              // Directeur de site
            $table->string('logo_path')->nullable();
            $table->json('settings')->nullable();                    // Config spécifique (devise, timezone, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ═══ 2. PIVOT UTILISATEUR ↔ FERME ═══
        Schema::create('farm_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);          // Ferme par défaut à la connexion
            $table->boolean('is_owner')->default(false);            // Propriétaire = voit toutes les fermes
            $table->timestamps();

            $table->unique(['farm_id', 'user_id']);
        });

        // ═══ 3. AJOUTER farm_id AUX TABLES MÉTIER ═══
        $tables = [
            'buildings', 'batches', 'daily_checks',
            'stocks', 'stock_movements',
            'employees', 'providers',
        ];

        // Tables qui existent peut-être (modules récents)
        $optionalTables = [
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
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'farm_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->foreignId('farm_id')->nullable()->after('id')->constrained()->nullOnDelete();
                    $table->index('farm_id');
                });
            }
        }

        foreach ($optionalTables as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'farm_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->foreignId('farm_id')->nullable()->after('id')->constrained()->nullOnDelete();
                    $table->index('farm_id');
                });
            }
        }

        // ═══ 4. CRÉER LA FERME PAR DÉFAUT ═══
        $farmId = DB::table('farms')->insertGetId([
            'name'       => 'Ferme Principale',
            'code'       => 'MAIN',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ═══ 5. ASSIGNER TOUTES LES DONNÉES EXISTANTES À LA FERME PAR DÉFAUT ═══
        $allTables = array_merge($tables, $optionalTables);
        foreach ($allTables as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'farm_id')) {
                DB::table($t)->whereNull('farm_id')->update(['farm_id' => $farmId]);
            }
        }

        // ═══ 6. ASSIGNER TOUS LES UTILISATEURS À LA FERME PAR DÉFAUT ═══
        $users = DB::table('users')->pluck('id');
        foreach ($users as $userId) {
            DB::table('farm_user')->insert([
                'farm_id'    => $farmId,
                'user_id'    => $userId,
                'is_default' => true,
                'is_owner'   => true, // Premier setup = tous propriétaires
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retirer farm_id de toutes les tables
        $allTables = [
            'buildings', 'batches', 'daily_checks', 'stocks', 'stock_movements',
            'employees', 'providers', 'egg_productions', 'incubations',
            'sales', 'sale_items', 'payments', 'clients', 'price_lists',
            'dispatches', 'dispatch_items', 'receptions', 'reception_items', 'discrepancy_reports',
            'water_sources', 'water_readings', 'energy_sources', 'energy_readings', 'fuel_purchases',
            'slaughter_orders', 'slaughter_results', 'cutting_sessions', 'cut_products',
            'finished_products', 'transformations', 'planned_batches',
            'raw_materials', 'formulas', 'mill_productions', 'mill_machines',
        ];

        foreach ($allTables as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'farm_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('farm_id');
                });
            }
        }

        Schema::dropIfExists('farm_user');
        Schema::dropIfExists('farms');
    }
};
