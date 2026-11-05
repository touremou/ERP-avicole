<?php

use App\Support\ProfilsMetier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROFILS MÉTIER ET ESPACE DE TRAVAIL DU TERRAIN.
 *
 * Décision de l'exploitation : des rôles prêts à l'emploi, chacun limité à ses
 * modules (App\Support\ProfilsMetier), et un « espace de travail » par rôle
 * que l'application terrain adapte (tuiles « Mon poste », indicateurs, ordre
 * du « + »).
 *
 *   • roles.workspace : l'espace déclaré ; vide, il se déduit des droits ;
 *   • les profils manquants sont AJOUTÉS — aucun rôle existant n'est touché,
 *     aucun utilisateur ne change de droits. Sur une installation neuve, les
 *     modules arrivent avec le seed : DatabaseSeeder installe alors les profils.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'workspace')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('workspace', 30)->nullable();
            });
        }

        ProfilsMetier::installer();
    }

    public function down(): void
    {
        $roles = DB::table('roles')->whereIn('name', array_keys(ProfilsMetier::PROFILS))->pluck('id');
        $occupes = DB::table('users')->whereIn('role_id', $roles)->pluck('role_id')->unique();
        $libres = $roles->diff($occupes);

        // Un profil déjà attribué reste : le retirer laisserait des comptes sans rôle.
        DB::table('module_permissions')->whereIn('role_id', $libres)->delete();
        DB::table('roles')->whereIn('id', $libres)->delete();

        if (Schema::hasColumn('roles', 'workspace')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('workspace');
            });
        }
    }
};
