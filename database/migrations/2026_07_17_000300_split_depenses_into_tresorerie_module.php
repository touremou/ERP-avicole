<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cloisonnement Finance : Dépenses/Achats vs Trésorerie — moindre privilège.
 *
 * Le module « depenses » mêlait la SAISIE (dépenses, achats fournisseurs,
 * budgets) et la TRÉSORERIE (comptes Caisse/Banque/Mobile Money, soldes,
 * mouvements, virements). Un comptable/saisisseur n'a pas à voir les soldes
 * bancaires ni à opérer des virements ; un trésorier gère les comptes sans
 * forcément saisir les dépenses.
 *
 * On extrait un module « tresorerie ». Choix retenu : MOINDRE PRIVILÈGE — le
 * nouveau module démarre SANS droit pour les rôles non-admin (l'admin garde
 * tout via le bypass). Les trésoriers devront se voir attribuer « tresorerie ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $tId = DB::table('modules')->where('slug', 'tresorerie')->value('id');
        if (! $tId) {
            $tId = DB::table('modules')->insertGetId([
                'name'          => 'Trésorerie',
                'slug'          => 'tresorerie',
                'icon'          => 'fa-wallet',
                'color'         => 'emerald',
                'display_order' => 8,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Clarifie l'intitulé du module depenses (désormais Dépenses / Achats).
        DB::table('modules')->where('slug', 'depenses')->update(['name' => 'Dépenses / Achats', 'updated_at' => now()]);

        // Moindre privilège : ligne tresorerie à FALSE pour chaque rôle.
        if (Schema::hasTable('module_permissions') && Schema::hasTable('roles')) {
            $now = now();
            foreach (DB::table('roles')->pluck('id') as $roleId) {
                DB::table('module_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'module_id' => $tId],
                    ['can_read' => false, 'can_create' => false, 'can_modify' => false,
                     'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $tId = DB::table('modules')->where('slug', 'tresorerie')->value('id');
        if ($tId && Schema::hasTable('module_permissions')) {
            DB::table('module_permissions')->where('module_id', $tId)->delete();
        }
        DB::table('modules')->where('slug', 'tresorerie')->delete();
        DB::table('modules')->where('slug', 'depenses')->update(['name' => 'Finance']);
    }
};
