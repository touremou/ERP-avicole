<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow d'approbation des congés :
 * - requested_by    : utilisateur ayant soumis la demande (traçabilité)
 * - approved_at     : horodatage de l'approbation
 * - rejection_reason: motif en cas de refus
 *
 * Permet de distinguer une demande (status 'demande') d'un congé approuvé,
 * et d'auto-approuver les saisies faites par une personne habilitée (RH /
 * Manager / Admin = droit annuaire.S).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_leaves', 'requested_by')) {
                $table->foreignId('requested_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('employee_leaves', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('requested_by');
            }
            if (! Schema::hasColumn('employee_leaves', 'rejection_reason')) {
                $table->string('rejection_reason', 500)->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table) {
            if (Schema::hasColumn('employee_leaves', 'requested_by')) {
                $table->dropConstrainedForeignId('requested_by');
            }
            foreach (['approved_at', 'rejection_reason'] as $col) {
                if (Schema::hasColumn('employee_leaves', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
