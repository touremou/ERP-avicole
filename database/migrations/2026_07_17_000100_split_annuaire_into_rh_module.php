<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cloisonnement Annuaire / RH (moindre privilège).
 *
 * Le module « annuaire » mêlait les TIERS (fournisseurs) et la RH INTERNE
 * (employés, paie, pointage, congés, tâches). Un rôle « Vendeur » à qui l'on
 * accordait l'annuaire pour gérer des fournisseurs héritait donc de l'accès
 * aux salaires et fiches de paie.
 *
 * On extrait un module « rh » distinct. Choix retenu : MOINDRE PRIVILÈGE — le
 * nouveau module démarre SANS aucun droit pour les rôles non-admin (l'admin
 * conserve tout via le bypass Gate::before). Les gestionnaires RH devront se
 * voir ré-attribuer explicitement le module « rh » via la matrice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        // 1. Le module RH (idempotent).
        $rhId = DB::table('modules')->where('slug', 'rh')->value('id');
        if (! $rhId) {
            $rhId = DB::table('modules')->insertGetId([
                'name'          => 'Ressources Humaines',
                'slug'          => 'rh',
                'icon'          => 'fa-user-tie',
                'color'         => 'violet',
                'display_order' => 10,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // 2. Clarifie l'intitulé du module annuaire (désormais TIERS seulement).
        DB::table('modules')->where('slug', 'annuaire')->update(['name' => 'Annuaire / Tiers', 'updated_at' => now()]);

        // 3. Moindre privilège : une ligne rh à FALSE pour chaque rôle (aucun
        //    accès par défaut). L'admin passe par le bypass, aucune ligne requise.
        if (Schema::hasTable('module_permissions') && Schema::hasTable('roles')) {
            $now = now();
            foreach (DB::table('roles')->pluck('id') as $roleId) {
                DB::table('module_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'module_id' => $rhId],
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

        $rhId = DB::table('modules')->where('slug', 'rh')->value('id');
        if ($rhId && Schema::hasTable('module_permissions')) {
            DB::table('module_permissions')->where('module_id', $rhId)->delete();
        }
        DB::table('modules')->where('slug', 'rh')->delete();
        DB::table('modules')->where('slug', 'annuaire')->update(['name' => 'Annuaire']);
    }
};
