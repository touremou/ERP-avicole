<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le modèle Role et plusieurs vues/contrôleurs (badge de rôle dans la nav,
 * gestion des rôles dans Utilisateurs, SetupController, DatabaseSeeder)
 * référencent roles.display_name et roles.icon, colonnes absentes de la
 * table réelle (qui n'a que `label`). On les ajoute et on les pré-remplit
 * à partir de `label` pour ne rien casser.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'display_name')) {
                $table->string('display_name')->nullable()->after('label');
            }
            if (!Schema::hasColumn('roles', 'icon')) {
                $table->string('icon', 10)->nullable()->after('display_name');
            }
        });

        DB::table('roles')->whereNull('display_name')->update([
            'display_name' => DB::raw('label'),
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
