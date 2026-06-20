<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Déclare le module « Production Végétale » (slug: cultures) dans la matrice RBAC.
 *
 * Même recette que le module « Dépenses » (2026_06_11_110001) :
 *  - insère la ligne `modules` (idempotent par slug) ;
 *  - pour chaque rôle DÉJÀ doté d'une matrice `module_permissions`, recopie ses
 *    permissions « elevage » (module de production comparable) ; à défaut, les
 *    flags globaux LCMS du rôle. Aucun rôle ne perd l'accès et les restrictions
 *    manuelles existantes sont respectées.
 *  - les rôles sans matrice conservent le fallback global (AppServiceProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $now = now();

        // ─── 1. Module ───
        $moduleId = DB::table('modules')->where('slug', 'cultures')->value('id');
        if (! $moduleId) {
            $moduleId = DB::table('modules')->insertGetId([
                'name'          => 'Production Végétale',
                'slug'          => 'cultures',
                'icon'          => 'fa-seedling',
                'color'         => 'green',
                'description'   => 'Parcelles, cycles de culture et récoltes',
                'display_order' => 13,
                'is_active'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        if (! Schema::hasTable('module_permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $elevageId = DB::table('modules')->where('slug', 'elevage')->value('id');

        // ─── 2. Permissions par rôle (uniquement ceux ayant déjà une matrice) ───
        foreach (DB::table('roles')->get() as $role) {
            $hasMatrix = DB::table('module_permissions')->where('role_id', $role->id)->exists();
            if (! $hasMatrix) {
                continue; // fallback global géré au runtime
            }

            $already = DB::table('module_permissions')
                ->where('role_id', $role->id)
                ->where('module_id', $moduleId)
                ->exists();
            if ($already) {
                continue;
            }

            // Référence : permissions « elevage » du rôle si présentes…
            $ref = $elevageId
                ? DB::table('module_permissions')
                    ->where('role_id', $role->id)
                    ->where('module_id', $elevageId)
                    ->first()
                : null;

            if ($ref) {
                $flags = [
                    'can_read'   => (bool) $ref->can_read,
                    'can_create' => (bool) $ref->can_create,
                    'can_modify' => (bool) $ref->can_modify,
                    'can_delete' => (bool) $ref->can_delete,
                ];
            } else {
                // …sinon, le rôle global LCMS.
                $perms = json_decode($role->permissions ?? '[]', true) ?: [];
                $flags = [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                ];
            }

            DB::table('module_permissions')->insert(array_merge([
                'role_id'    => $role->id,
                'module_id'  => $moduleId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $flags));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $moduleId = DB::table('modules')->where('slug', 'cultures')->value('id');
        if ($moduleId && Schema::hasTable('module_permissions')) {
            DB::table('module_permissions')->where('module_id', $moduleId)->delete();
        }
        DB::table('modules')->where('slug', 'cultures')->delete();
    }
};
