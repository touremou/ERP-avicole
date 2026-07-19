<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clôture de cycle d'abattage (lot D) — checklist HACCP / déchets de fin de
 * cycle. Après l'exécution, une clôture SIGNÉE atteste du traitement des
 * déchets (sang, plumes, viscères → circuit séparé) et du respect du plan
 * sanitaire (nettoyage/désinfection, marche en avant). Tant qu'elle n'est pas
 * faite, l'ordre est « terminé » mais pas « clos » — et le dossier de lot
 * intègre la clôture (opposable à un inspecteur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->timestamp('closed_at')->nullable()->after('actual_date');
            $t->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $t->json('closure_checklist')->nullable()->after('closed_by'); // confirmations + instantané des contrôles auto
        });
    }

    public function down(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['closed_at', 'closure_checklist']);
        });
    }
};
