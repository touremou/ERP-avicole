<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Complète la matrice `module_permissions` pour tout rôle créé après la
 * migration 2026_06_10_000004 (ex: "Opérateur"), qui n'a reçu AUCUNE ligne
 * et dépendait donc entièrement du fallback LCMS global (`roles.permissions`).
 *
 * Bug observé : dès qu'un admin coche UNE seule case dans la Matrice Modules
 * pour un tel rôle (ex: annuaire.L), `module_permissions` devient non vide
 * et fait alors AUTORITÉ POUR TOUS LES MODULES — le rôle perd instantanément
 * tous ses accès implicites (ex: elevage.L/C) sur les modules non cochés.
 *
 * On garantit donc qu'AUCUN rôle n'a de matrice partielle : chaque rôle
 * reçoit une ligne par module, initialisée à partir de `roles.permissions`
 * (comportement LCMS historique) pour les modules qui n'ont pas encore de
 * ligne. Les lignes déjà configurées manuellement ne sont jamais modifiées.
 */
return new class extends Migration {
    public function up(): void
    {
        $moduleIds = DB::table('modules')->pluck('id');
        if ($moduleIds->isEmpty()) {
            return;
        }

        $now = now();

        foreach (DB::table('roles')->get() as $role) {
            $existingModuleIds = DB::table('module_permissions')
                ->where('role_id', $role->id)
                ->pluck('module_id')
                ->all();

            $missingModuleIds = $moduleIds->diff($existingModuleIds);
            if ($missingModuleIds->isEmpty()) {
                continue;
            }

            $perms = json_decode($role->permissions ?? '[]', true) ?: [];

            $rows = [];
            foreach ($missingModuleIds as $moduleId) {
                $rows[] = [
                    'role_id'    => $role->id,
                    'module_id'  => $moduleId,
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('module_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        // Pas de rollback : suppression de configurations potentiellement
        // personnalisées par un administrateur après cette migration.
    }
};
