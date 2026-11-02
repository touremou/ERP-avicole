<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SUJETS MORTS À LA LIVRAISON : LA RÈGLE DU CONTRAT FOURNISSEUR.
 *
 * Cf. App\Support\MortsALaLivraison. Le fournisseur porte la règle de son
 * contrat ; le lot la fige à sa mise en place, avec la charge « Pertes au
 * transport » qu'elle produit (règle C). Colonnes vides pour l'historique :
 * elles valent la règle A, celle qu'appliquait déjà la mise en lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('providers', 'doa_policy')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->string('doa_policy', 20)->nullable()->after('payment_terms');
            });
        }

        Schema::table('batches', function (Blueprint $table) {
            if (! Schema::hasColumn('batches', 'doa_policy')) {
                $table->string('doa_policy', 20)->nullable()->after('total_acquisition_cost');
            }
            if (! Schema::hasColumn('batches', 'transport_loss_cost')) {
                $table->decimal('transport_loss_cost', 12, 2)->default(0)->after('total_acquisition_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            foreach (['doa_policy', 'transport_loss_cost'] as $colonne) {
                if (Schema::hasColumn('batches', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });

        if (Schema::hasColumn('providers', 'doa_policy')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->dropColumn('doa_policy');
            });
        }
    }
};
