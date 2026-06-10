<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration corrective : ajoute farm_id à TOUTES les tables métier
 * qui ne l'ont pas encore.
 *
 * Tables EXCLUES (globales, pas liées à une ferme) :
 * users, roles, permissions, module_permissions, modules,
 * farms, farm_user, production_norms, protocols,
 * notification_preferences, notification_logs,
 * migrations, password_reset_tokens, sessions, cache, jobs, etc.
 */
return new class extends Migration
{
    /**
     * Tables à EXCLURE (globales / système).
     */
    private function getExcludedTables(): array
    {
        return [
            // Système Laravel
            'migrations', 'password_reset_tokens', 'sessions',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'personal_access_tokens',
            // Auth / RBAC
            'users', 'roles', 'permissions', 'role_permission', 'permission_role',
            'modules', 'module_permissions',
            // Multi-ferme (tables parentes)
            'farms', 'farm_user',
            // Référentiels partagés entre fermes
            'production_norms', 'protocols',
            // Notifications (liées au user, pas à la ferme)
            'notification_preferences', 'notification_logs',
        ];
    }

    public function up(): void
    {
        $excluded = $this->getExcludedTables();
        $defaultFarmId = DB::table('farms')->where('code', 'MAIN')->value('id')
            ?? DB::table('farms')->first()?->id;

        if (! $defaultFarmId) {
            // Pas de ferme = rien à faire
            return;
        }

        // Récupérer toutes les tables de la base (cross-DB: MySQL and SQLite)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $allTables = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
                ->map(fn($row) => $row->name)
                ->toArray();
        } else {
            $allTables = collect(DB::select('SHOW TABLES'))
                ->map(fn($row) => array_values((array) $row)[0])
                ->toArray();
        }

        $added = [];

        foreach ($allTables as $table) {
            // Sauter les tables exclues
            if (in_array($table, $excluded)) continue;

            // Sauter si farm_id existe déjà
            if (Schema::hasColumn($table, 'farm_id')) continue;

            // Sauter les tables pivot simples (pas de colonne id)
            if (! Schema::hasColumn($table, 'id')) continue;

            // Ajouter farm_id
            try {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('farm_id')->nullable()->after('id');
                    $blueprint->index('farm_id');
                });

                // Assigner les données existantes à la ferme par défaut
                DB::table($table)->whereNull('farm_id')->update(['farm_id' => $defaultFarmId]);

                $added[] = $table;
            } catch (\Throwable $e) {
                // Si une table pose problème, on log et on continue
                logger()->warning("Multi-farm migration: impossible d'ajouter farm_id à {$table}: {$e->getMessage()}");
            }
        }

        if (count($added) > 0) {
            logger()->info("Multi-farm migration corrective: farm_id ajouté à " . implode(', ', $added));
        }
    }

    public function down(): void
    {
        // On ne retire pas farm_id dans le down pour éviter la perte de données
    }
};
