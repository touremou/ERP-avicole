<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 4 (refonte désassemblage) — découpe depuis le mobile : uuid client sur
 * les sessions de découpe pour l'idempotence du push offline (un rejeu de
 * l'opération ne recrée jamais une seconde session).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutting_sessions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('cutting_sessions', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
