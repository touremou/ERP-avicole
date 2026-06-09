<?php

/**
 * MIGRATION CORRECTIVE — DÉPRÉCIATION COLONNES LEGACY
 *
 * 1. batches.qty_alive → marquée dépréciée (sera un accessor à terme)
 * 2. users.role (varchar) → notifiée comme legacy (le RBAC utilise role_id → roles)
 *
 * Bugs corrigés : B-05 (qty_alive incohérent)
 * Décision d'architecture : §2.1 — current_quantity = source de vérité
 *
 * IMPORTANT : Cette migration ne SUPPRIME rien. Elle ajoute des commentaires
 * et garantit que les valeurs de qty_alive sont synchronisées avec current_quantity.
 *
 * @see AUDIT_MODULE_LOTS.md — Section B-05 et Décision 2.1
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────
        // 1. SYNCHRONISER qty_alive AVEC current_quantity
        // ─────────────────────────────────────────────
        // Si qty_alive != current_quantity, c'est current_quantity qui a raison
        // (car les observers DailyCheck le mettent à jour, pas qty_alive).
        if (Schema::hasColumn('batches', 'qty_alive') && Schema::hasColumn('batches', 'current_quantity')) {
            $desyncCount = DB::table('batches')
                ->whereColumn('qty_alive', '!=', 'current_quantity')
                ->count();

            if ($desyncCount > 0) {
                DB::table('batches')
                    ->whereColumn('qty_alive', '!=', 'current_quantity')
                    ->update(['qty_alive' => DB::raw('current_quantity')]);

                // Log pour traçabilité
                \Illuminate\Support\Facades\Log::info(
                    "[Migration] batches.qty_alive synchronisé avec current_quantity pour {$desyncCount} lot(s)."
                );
            }
        }

        // ─────────────────────────────────────────────
        // 2. COMMENTAIRE SQL DE DÉPRÉCIATION (MySQL 5.7+ / MariaDB 10.3+)
        // ─────────────────────────────────────────────
        // Les commentaires SQL sont la meilleure façon de documenter une dépréciation
        // sans casser le code existant.
        try {
            DB::statement(
                "ALTER TABLE `batches` MODIFY COLUMN `qty_alive` INT UNSIGNED NOT NULL DEFAULT 0 " .
                "COMMENT 'DEPRECATED 2026-05 : Utiliser current_quantity comme source de vérité. " .
                "Cette colonne sera supprimée en v2. Voir AUDIT_MODULE_LOTS.md §B-05.'"
            );
        } catch (\Exception $e) {
            // Si la syntaxe ne passe pas (versions très anciennes), on continue
            \Illuminate\Support\Facades\Log::warning(
                "[Migration] Impossible d'ajouter le commentaire de dépréciation sur batches.qty_alive : " .
                $e->getMessage()
            );
        }

        // Même traitement pour breeding_type si elle existe encore
        if (Schema::hasColumn('batches', 'breeding_type')) {
            try {
                DB::statement(
                    "ALTER TABLE `batches` MODIFY COLUMN `breeding_type` " .
                    "ENUM('Chair','Pondeuse','Reproducteur') NULL " .
                    "COMMENT 'DEPRECATED 2026-05 : Utiliser la colonne type à la place. " .
                    "Sera supprimée en v2.'"
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "[Migration] Commentaire dépréciation breeding_type échoué : " . $e->getMessage()
                );
            }
        }

        // ─────────────────────────────────────────────
        // 3. COMMENTAIRE DÉPRÉCIATION SUR users.role
        // ─────────────────────────────────────────────
        if (Schema::hasColumn('users', 'role')) {
            try {
                DB::statement(
                    "ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(191) NOT NULL DEFAULT 'worker' " .
                    "COMMENT 'DEPRECATED 2026-05 : Utiliser role_id → roles → permissions. " .
                    "Voir BatchObserver et DashboardController pour les usages legacy.'"
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "[Migration] Commentaire dépréciation users.role échoué : " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Rollback : La synchro qty_alive est réversible (on ne peut pas restaurer
     * l'ancienne valeur désynchronisée car elle était fausse).
     * Les commentaires SQL peuvent être retirés mais ce n'est pas nécessaire.
     */
    public function down(): void
    {
        // Retrait des commentaires de dépréciation (optionnel)
        try {
            if (Schema::hasColumn('batches', 'qty_alive')) {
                DB::statement(
                    "ALTER TABLE `batches` MODIFY COLUMN `qty_alive` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''"
                );
            }
        } catch (\Exception $e) {
            // Pas critique
        }
    }
};
