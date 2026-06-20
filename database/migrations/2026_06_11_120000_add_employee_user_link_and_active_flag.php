<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace employé — pont entre la fiche RH (Employee) et un compte de
 * connexion (User), + interrupteur d'activation des comptes.
 *
 * - users.is_active : un compte désactivé ne peut plus se connecter
 *   (contrôle de sécurité vérifié au login). Défaut true (comptes existants
 *   restent actifs).
 * - employees.user_id : lien 1-1 optionnel vers le compte de connexion de
 *   l'employé (nullable + unique : un employé a au plus un accès, un compte
 *   est rattaché à au plus un employé).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('role_id');
            });
        }

        if (! Schema::hasColumn('employees', 'user_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->unique()
                    ->after('id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'user_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        if (Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
