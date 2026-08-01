<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cut_products.destination` : ENUM figé en juin → chaîne, comme ses colonnes
 * sœurs.
 *
 * Trouvé en rejouant la suite de tests sur MySQL.
 *
 * L'ENUM d'origine (2026_06_01) liste stock_frais / stock_congele /
 * transformation / vente_directe. En juillet, la découpe a gagné la ligne
 * « déchet » : le contrôleur l'accepte explicitement
 * (SlaughterController::555) et le service la traite (pesée pour la balance de
 * masse, jamais mise en stock, jamais porteuse de coût).
 *
 * Personne n'a élargi l'ENUM. Sur sqlite — qui n'applique pas les types — les
 * tests passaient ; sur MySQL, la base REFUSE la valeur. Autrement dit :
 * enregistrer une découpe déclarant le moindre déchet échouait en production,
 * ce qui est le cas courant, pas le cas limite.
 *
 * On repasse en `string(30)` plutôt que d'ajouter une valeur à l'ENUM :
 * `cutting_recipes.default_destination` et `slaughter_byproducts.destination`
 * sont DÉJÀ des varchar(30). Deux représentations pour une même notion, c'est
 * exactement ce qui a produit la divergence ; on n'en garde qu'une. La liste
 * autorisée reste tenue à l'entrée, par la validation du contrôleur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cut_products') || ! Schema::hasColumn('cut_products', 'destination')) {
            return;
        }

        Schema::table('cut_products', function (Blueprint $table) {
            $table->string('destination', 30)->default('stock_frais')->change();
        });
    }

    public function down(): void
    {
        // Pas de retour à l'ENUM : il rejetterait les lignes « dechet » déjà
        // enregistrées, et ferait échouer le rollback sur des données réelles.
    }
};
