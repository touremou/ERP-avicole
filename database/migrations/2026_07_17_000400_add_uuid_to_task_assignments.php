<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * uuid d'idempotence pour la création de tâches depuis le terrain (PWA).
 * Nullable + unique : les tâches web/auto-générées restent sans uuid, les
 * tâches créées hors-ligne portent l'uuid généré côté mobile (rejeu sûr).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_assignments') || Schema::hasColumn('task_assignments', 'uuid')) {
            return;
        }

        Schema::table('task_assignments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments') && Schema::hasColumn('task_assignments', 'uuid')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};
