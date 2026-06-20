<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Déclare le module « Dépenses » (slug: depenses) dans la matrice RBAC.
 *
 * - Insère la ligne dans `modules` (idempotent par slug).
 * - Pour chaque rôle DÉJÀ doté d'une matrice `module_permissions`, ajoute une
 *   ligne pour le nouveau module en recopiant ses permissions « commerce »
 *   (module financier comparable) ; à défaut, les flags du rôle global LCMS.
 *   Ainsi, aucun rôle ne perd l'accès et les restrictions manuelles existantes
 *   (ex: opérateur sans commerce) sont respectées.
 * - Les rôles sans matrice du tout conservent le fallback global (AppServiceProvider).
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
        $moduleId = DB::table('modules')->where('slug', 'depenses')->value('id');
        if (! $moduleId) {
            $moduleId = DB::table('modules')->insertGetId([
                'name'          => 'Dépenses',
                'slug'          => 'depenses',
                'icon'          => 'fa-receipt',
                'color'         => 'rose',
                'description'   => 'Registre des dépenses ponctuelles et frais divers',
                'display_order' => 12,
                'is_active'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        if (! Schema::hasTable('module_permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $commerceId = DB::table('modules')->where('slug', 'commerce')->value('id');

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

            // Référence : permissions « commerce » du rôle si présentes…
            $ref = $commerceId
                ? DB::table('module_permissions')
                    ->where('role_id', $role->id)
                    ->where('module_id', $commerceId)
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

        $moduleId = DB::table('modules')->where('slug', 'depenses')->value('id');
        if ($moduleId && Schema::hasTable('module_permissions')) {
            DB::table('module_permissions')->where('module_id', $moduleId)->delete();
        }
        DB::table('modules')->where('slug', 'depenses')->delete();
    }
};
