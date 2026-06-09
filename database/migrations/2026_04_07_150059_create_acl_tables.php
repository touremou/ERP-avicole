<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    // 1. Table des Rôles (ex: Admin, Chef de production, Magasinier)
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique(); // ex: 'admin'
        $table->string('display_name');  // ex: 'Administrateur'
        $table->string('icon')->nullable(); // ex: 'fa-shield'
        $table->timestamps();
    });

    // 2. Table des Permissions (ex: 'create_production', 'edit_machine')
    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('description')->nullable();
        $table->timestamps();
    });

    // 3. Table Pivot (Liaison Rôles <-> Permissions)
    Schema::create('permission_role', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->onDelete('cascade');
        $table->foreignId('permission_id')->constrained()->onDelete('cascade');
        $table->primary(['role_id', 'permission_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acl_tables');
    }
};
