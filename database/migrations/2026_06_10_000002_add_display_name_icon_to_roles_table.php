<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le modèle Role et plusieurs vues/contrôleurs (badge de rôle dans la nav,
 * gestion des rôles dans Utilisateurs, SetupController, DatabaseSeeder)
 * référencent roles.display_name, roles.icon, roles.label et
 * roles.permissions (JSON L/C/M/S).
 *
 * Selon l'historique de la base, la table `roles` a pu être créée par
 * 2026_04_07_150059_create_acl_tables (schéma : id, name, display_name,
 * icon) OU déjà étendue manuellement avec label/permissions/description.
 * Cette migration ajoute les colonnes manquantes dans les deux cas et
 * harmonise label <-> display_name + permissions par défaut, sans rien
 * casser.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'label')) {
                $table->string('label')->nullable()->after('name');
            }
            if (!Schema::hasColumn('roles', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('roles', 'permissions')) {
                $table->json('permissions')->nullable();
            }
            if (!Schema::hasColumn('roles', 'display_name')) {
                $table->string('display_name')->nullable();
            }
            if (!Schema::hasColumn('roles', 'icon')) {
                $table->string('icon', 10)->nullable();
            }
        });

        // Sur une base fraîche, 2026_04_07_150059_create_acl_tables crée la
        // table `roles` mais ne la peuple pas (le DatabaseSeeder est cassé
        // par ailleurs). On garantit donc l'existence des 5 grades de base.
        $baseRoles = [
            ['name' => 'admin', 'label' => 'Administrateur', 'display_name' => 'Administrateur', 'icon' => '👑', 'permissions' => ['L', 'C', 'M', 'S']],
            ['name' => 'manager', 'label' => 'Manager', 'display_name' => 'Manager', 'icon' => '📊', 'permissions' => ['L', 'C', 'M']],
            ['name' => 'operator', 'label' => 'Opérateur', 'display_name' => 'Opérateur', 'icon' => '👷', 'permissions' => ['L', 'C']],
            ['name' => 'viewer', 'label' => 'Lecteur', 'display_name' => 'Lecteur', 'icon' => '👁️', 'permissions' => ['L']],
            ['name' => 'ouvrier', 'label' => 'Ouvrier', 'display_name' => 'Ouvrier', 'icon' => '👷', 'permissions' => []],
        ];

        foreach ($baseRoles as $role) {
            if (!DB::table('roles')->where('name', $role['name'])->exists()) {
                DB::table('roles')->insert(array_merge($role, [
                    'permissions' => json_encode($role['permissions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // Harmonise label <-> display_name (l'un peut manquer selon l'origine de la table)
        DB::table('roles')->whereNull('display_name')->whereNotNull('label')->update([
            'display_name' => DB::raw('label'),
        ]);
        DB::table('roles')->whereNull('label')->whereNotNull('display_name')->update([
            'label' => DB::raw('display_name'),
        ]);

        $defaultIcons = [
            'admin' => '👑',
            'manager' => '📊',
            'operator' => '👷',
            'viewer' => '👁️',
            'ouvrier' => '👷',
            'worker' => '👷',
        ];

        foreach ($defaultIcons as $name => $icon) {
            DB::table('roles')->where('name', $name)->whereNull('icon')->update(['icon' => $icon]);
        }

        DB::table('roles')->whereNull('icon')->update(['icon' => '👤']);

        // Permissions globales (LCMS) par défaut, alignées sur le seeder d'origine
        $defaultPermissions = [
            'admin' => ['L', 'C', 'M', 'S'],
            'manager' => ['L', 'C', 'M'],
            'operator' => ['L', 'C'],
            'viewer' => ['L'],
            'ouvrier' => ['L'],
            'worker' => ['L'],
        ];

        foreach ($defaultPermissions as $name => $perms) {
            DB::table('roles')->where('name', $name)->whereNull('permissions')->update([
                'permissions' => json_encode($perms),
            ]);
        }

        DB::table('roles')->whereNull('permissions')->update(['permissions' => json_encode([])]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'icon')) {
                $table->dropColumn('icon');
            }
            if (Schema::hasColumn('roles', 'display_name')) {
                $table->dropColumn('display_name');
            }
        });
    }
};
