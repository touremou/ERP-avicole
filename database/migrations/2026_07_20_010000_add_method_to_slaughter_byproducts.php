<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre sous-produits (E9) — méthode de quantification :
 *  - « pese »  : pesée réelle (balance) — valeur par défaut, rétrocompat ;
 *  - « estime »: estimation zootechnique (poids vif × ratio d'espèce) générée
 *    automatiquement à l'exécution de l'abattage.
 * Tracer la méthode garde le registre opposable : un chiffre estimé par un
 * ratio standard est défendable, une fausse pesée ne l'est pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_byproducts', function (Blueprint $table) {
            $table->string('method', 10)->default('pese')->after('quantity_kg');
        });
    }

    public function down(): void
    {
        Schema::table('slaughter_byproducts', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
