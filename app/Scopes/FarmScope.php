<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * FarmScope — Filtre automatique par ferme courante.
 *
 * Sécurités :
 * 1. S'active UNIQUEMENT si session('current_farm_id') est défini
 * 2. Vérifie que la table a bien une colonne farm_id avant de filtrer
 * 3. Cache le résultat de la vérification pour la performance
 *
 * Si pas de ferme en session → aucun filtre (rétrocompatible mono-ferme).
 * Si la table n'a pas farm_id → aucun filtre (pas de crash).
 */
class FarmScope implements Scope
{
    /**
     * Cache statique : évite de vérifier Schema::hasColumn à chaque requête.
     */
    private static array $tableHasFarmId = [];

    public function apply(Builder $builder, Model $model): void
    {
        $farmId = session('current_farm_id');

        if (! $farmId) return;

        $table = $model->getTable();

        // Vérifier (avec cache) que la table a une colonne farm_id
        if (! $this->tableHasFarmIdColumn($table)) return;

        $builder->where("{$table}.farm_id", $farmId);
    }

    /**
     * Vérifie si la table a une colonne farm_id, avec mise en cache.
     */
    private function tableHasFarmIdColumn(string $table): bool
    {
        if (! isset(self::$tableHasFarmId[$table])) {
            try {
                self::$tableHasFarmId[$table] = Schema::hasColumn($table, 'farm_id');
            } catch (\Throwable $e) {
                self::$tableHasFarmId[$table] = false;
            }
        }

        return self::$tableHasFarmId[$table];
    }

    /**
     * Vider le cache (utile après migration).
     */
    public static function clearCache(): void
    {
        self::$tableHasFarmId = [];
    }
}
