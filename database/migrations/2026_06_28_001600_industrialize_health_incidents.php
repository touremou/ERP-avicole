<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industrialisation des incidents sanitaires : lien au LOT, niveau de gravité,
 * traçabilité du diagnostic et de la résolution (qui/quand + notes), coût de
 * traitement, et suivi de quarantaine. Idempotent (gardes hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('health_incidents')) {
            return;
        }

        Schema::table('health_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('health_incidents', 'batch_id')) {
                $table->foreignId('batch_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('health_incidents', 'severity')) {
                // mineur | modere | critique — pilote priorisation et alertes.
                $table->string('severity', 20)->default('modere')->after('status');
            }
            if (! Schema::hasColumn('health_incidents', 'diagnosed_by')) {
                $table->foreignId('diagnosed_by')->nullable()->after('vet_prescription')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('health_incidents', 'diagnosed_at')) {
                $table->timestamp('diagnosed_at')->nullable()->after('diagnosed_by');
            }
            if (! Schema::hasColumn('health_incidents', 'treatment_cost')) {
                $table->decimal('treatment_cost', 14, 2)->default(0)->after('diagnosed_at');
            }
            if (! Schema::hasColumn('health_incidents', 'resolved_by')) {
                $table->foreignId('resolved_by')->nullable()->after('treatment_cost')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('health_incidents', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            }
            if (! Schema::hasColumn('health_incidents', 'resolution_notes')) {
                $table->text('resolution_notes')->nullable()->after('resolved_at');
            }
            if (! Schema::hasColumn('health_incidents', 'is_quarantined')) {
                $table->boolean('is_quarantined')->default(false)->after('resolution_notes');
            }
            if (! Schema::hasColumn('health_incidents', 'quarantine_started_at')) {
                $table->timestamp('quarantine_started_at')->nullable()->after('is_quarantined');
            }
            if (! Schema::hasColumn('health_incidents', 'quarantine_ended_at')) {
                $table->timestamp('quarantine_ended_at')->nullable()->after('quarantine_started_at');
            }
        });
    }

    public function down(): void
    {
        // Conservateur : on ne retire pas les colonnes (préserve les données).
    }
};
