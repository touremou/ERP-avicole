<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INSTANTANÉ DE LA CATÉGORIE ET DU LOT SUR UNE LIGNE DE RETOUR.
 *
 * Un retour de marchandise décrémente la ligne de vente d'origine — et la
 * SUPPRIME si le retour est total. `sale_items` n'a pas de suppression douce :
 * la ligne disparaît pour de bon, emportant `product_type` et `batch_id`.
 *
 * Or ce sont exactement les deux clefs par lesquelles les rapports ventilent le
 * chiffre d'affaires : le compte de résultat groupe par CATÉGORIE de produit, la
 * rentabilité par espèce passe par le LOT. Sans instantané, un retour rend son
 * chiffre non rattachable.
 *
 * Ces deux colonnes sont des COPIES, délibérément sans clef étrangère : leur
 * raison d'être est de survivre à la disparition de la ligne source.
 *
 * NULLABLE, et c'est assumé : les retours enregistrés AVANT cette migration
 * n'ont pas de quoi être renseignés. Les rapports les comptent sous un libellé
 * distinct plutôt que de les ranger au hasard dans une catégorie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_return_items')) {
            return;
        }

        Schema::table('sale_return_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_return_items', 'product_type')) {
                $table->string('product_type')->nullable()->after('product_name');
            }

            if (! Schema::hasColumn('sale_return_items', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('product_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sale_return_items')) {
            return;
        }

        Schema::table('sale_return_items', function (Blueprint $table) {
            foreach (['product_type', 'batch_id'] as $column) {
                if (Schema::hasColumn('sale_return_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
