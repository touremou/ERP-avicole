<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gammes de sortie carcasse (lot A) — présentation choisie à l'exécution :
 * PAC (prêt-à-cuire, vidé), effilé (têtes/pattes), ou brut (à découper). Détermine
 * le nom de l'article de stock produit et la bande de rendement attendue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_results', function (Blueprint $t) {
            $t->string('presentation', 10)->default('brut')->after('carcass_yield_percent'); // brut | pac | effile
        });
    }

    public function down(): void
    {
        Schema::table('slaughter_results', function (Blueprint $t) {
            $t->dropColumn('presentation');
        });
    }
};
