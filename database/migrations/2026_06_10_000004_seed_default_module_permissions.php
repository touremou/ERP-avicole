<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La matrice Modules × Rôles (`module_permissions`) devient la source de
 * vérité pour les Gates L/C/M/S (cf. AppServiceProvider). Sur une base
 * fraîche — ou pour tout rôle jamais configuré via l'écran Utilisateurs —
 * cette table est vide, ce qui couperait l'accès à tous les modules pour
 * les rôles non-admin.
 *
 * Pour préserver le comportement historique (permissions globales LCMS par
 * rôle) tant qu'un admin n'a pas affiné la matrice, on initialise une ligne
 * par module pour chaque rôle qui n'a ENCORE AUCUNE ligne dans
 * `module_permissions`, à partir de `roles.permissions` (LCMS global).
 *
 * Un rôle déjà présent dans `module_permissions` (matrice configurée
 * manuellement, ex: opérateur restreint à elevage.L) n'est PAS modifié.
 */
return new class extends Migration {
    public function up(): void
    {
        $modules = DB::table('modules')->pluck('id', 'slug');
        if ($modules->isEmpty()) {
            return;
        }

        $now = now();

        foreach (DB::table('roles')->get() as $role) {
            $hasMatrix = DB::table('module_permissions')->where('role_id', $role->id)->exists();
            if ($hasMatrix) {
                continue;
            }

            $perms = json_decode($role->permissions ?? '[]', true) ?: [];

            $rows = [];
            foreach ($modules as $moduleId) {
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
