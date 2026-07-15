<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * uuid sur health_incidents : clé d'idempotence pour la déclaration
 * d'incident depuis le terrain (sync push mobile, Phase 1) — même schéma que
 * daily_checks/egg_productions. Backfill des lignes existantes puis index
 * unique. Idempotent (garde hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('health_incidents') || Schema::hasColumn('health_incidents', 'uuid')) {
            return;
        }

        Schema::table('health_incidents', function (Blueprint $table) {
            $table->char('uuid', 36)->nullable()->after('id');
        });

        foreach (DB::table('health_incidents')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('health_incidents')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        try {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->unique('uuid', 'health_incidents_uuid_unique');
            });
        } catch (\Throwable $e) {
            // Index déjà présent — on ignore.
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('health_incidents', 'uuid')) {
            return;
        }

        Schema::table('health_incidents', function (Blueprint $table) {
            $table->dropUnique('health_incidents_uuid_unique');
            $table->dropColumn('uuid');
        });
    }
};
