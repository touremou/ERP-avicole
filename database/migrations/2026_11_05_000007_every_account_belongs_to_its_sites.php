<?php

use App\Support\ProfilsMetier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHAQUE COMPTE RATTACHÉ À SES SITES — et deux profils de plus.
 *
 * Aucune des trois façons de créer un accès ne rattachait le compte à un site :
 * il retombait sur la ferme principale, où il n'était affecté nulle part.
 * Les créations le rattachent désormais (App\Support\SitesDuCompte) ; ceux
 * qui existent le sont ici, sans que personne ne perde son accès :
 *
 *   • au site de sa fiche employé, s'il en a une (et qu'il est en service) ;
 *   • sinon à la ferme principale — là où il travaillait jusqu'ici.
 *
 * L'administrateur de l'installation n'est pas concerné : il garde le repli.
 * Profils ajoutés : Vétérinaire, Auditeur / contrôleur (lecture seule).
 */
return new class extends Migration
{
    public function up(): void
    {
        ProfilsMetier::installer();

        if (! Schema::hasTable('farm_user')) {
            return;
        }

        $principale = DB::table('farms')->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->value('id');
        if (! $principale) {
            return;
        }

        $admin = DB::table('roles')->where('name', 'admin')->value('id');

        $sansSite = DB::table('users')
            ->whereNotIn('id', DB::table('farm_user')->select('user_id'))
            ->when($admin, fn ($q) => $q->where(fn ($w) => $w->whereNull('role_id')->orWhere('role_id', '!=', $admin)))
            ->pluck('id');

        foreach ($sansSite as $compte) {
            $siteDeLEmploye = DB::table('employees')
                ->join('farms', 'farms.id', '=', 'employees.farm_id')
                ->where('employees.user_id', $compte)
                ->where('farms.is_active', true)->whereNull('farms.deleted_at')
                ->value('employees.farm_id');

            DB::table('farm_user')->insert([
                'farm_id'    => $siteDeLEmploye ?: $principale,
                'user_id'    => $compte,
                'is_default' => true,
                'is_owner'   => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Rattachements : un constat sur des données, rien à défaire.
    }
};
