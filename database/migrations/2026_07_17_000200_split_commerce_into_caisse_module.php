<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cloisonnement Commerce (Ventes) / Caisse (POS) — moindre privilège.
 *
 * Le module « commerce » mêlait la VENTE back-office (clients, bons/factures,
 * recouvrement, tarifs, avoirs, catalogue) et la CAISSE front-office (point de
 * vente, sessions de caisse). Un caissier n'a pas à voir le carnet clients, le
 * recouvrement ou l'annulation de factures ; un commercial n'opère pas
 * forcément la caisse physique.
 *
 * On extrait un module « caisse » (POS + sessions de caisse). Choix retenu :
 * MOINDRE PRIVILÈGE — le nouveau module démarre SANS droit pour les rôles
 * non-admin (l'admin conserve tout via le bypass Gate::before). Les caissiers
 * devront se voir attribuer explicitement le module « caisse » via la matrice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $caisseId = DB::table('modules')->where('slug', 'caisse')->value('id');
        if (! $caisseId) {
            $caisseId = DB::table('modules')->insertGetId([
                'name'          => 'Caisse / POS',
                'slug'          => 'caisse',
                'icon'          => 'fa-cash-register',
                'color'         => 'teal',
                'display_order' => 6,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Clarifie l'intitulé du module commerce (désormais VENTES).
        DB::table('modules')->where('slug', 'commerce')->update(['name' => 'Commerce / Ventes', 'updated_at' => now()]);

        // Moindre privilège : une ligne caisse à FALSE pour chaque rôle.
        if (Schema::hasTable('module_permissions') && Schema::hasTable('roles')) {
            $now = now();
            foreach (DB::table('roles')->pluck('id') as $roleId) {
                DB::table('module_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'module_id' => $caisseId],
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

        $caisseId = DB::table('modules')->where('slug', 'caisse')->value('id');
        if ($caisseId && Schema::hasTable('module_permissions')) {
            DB::table('module_permissions')->where('module_id', $caisseId)->delete();
        }
        DB::table('modules')->where('slug', 'caisse')->delete();
        DB::table('modules')->where('slug', 'commerce')->update(['name' => 'Commerce']);
    }
};
