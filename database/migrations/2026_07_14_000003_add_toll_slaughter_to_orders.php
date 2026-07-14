<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abattage à façon (spec Transformation E8) — prestation de service : le
 * client apporte SES volailles, l'abattoir facture le geste, jamais la
 * marchandise (RG-07 : les produits restent propriété du client et
 * n'entrent PAS dans le stock vendable).
 *
 * Trois modèles de facturation standards, paramétrables (Réglages >
 * abattoir), figés SUR L'ORDRE à sa création (un changement de tarif
 * ultérieur ne réécrit pas un devis accepté) :
 *   - par_sujet       : GNF / tête abattue (le plus courant, vérifiable) ;
 *   - par_kg_vif      : GNF / kg vif pesé à la réception (pesée
 *                       contradictoire — sujets hétérogènes) ;
 *   - par_kg_carcasse : GNF / kg carcasse produit (pesée sortie — usuel
 *                       pour les professionnels, incite au soin).
 * Plus un minimum forfaitaire par ordre (mise en route).
 *
 * À l'exécution : prestation calculée (service_fee) et FACTURE BROUILLON
 * générée dans le module Commerce (service_sale_id) — la validation reste
 * une décision humaine, comme toute vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->string('service_type', 10)->default('propre');   // propre | facon
            $t->string('billing_model', 20)->nullable();          // par_sujet | par_kg_vif | par_kg_carcasse
            $t->decimal('billing_rate', 12, 2)->nullable();       // GNF, figé à la création
            $t->decimal('service_fee', 14, 2)->nullable();        // calculé à l'exécution
            $t->foreignId('service_sale_id')->nullable()->constrained('sales')->nullOnDelete();
        });

        $now = now();
        $tarifs = [
            ['key' => 'facon_rate_per_bird',       'value' => '2500', 'label' => 'Façon — tarif par sujet',        'unit' => 'GNF',    'display_order' => 40],
            ['key' => 'facon_rate_per_kg_live',    'value' => '1200', 'label' => 'Façon — tarif par kg vif',       'unit' => 'GNF/kg', 'display_order' => 41],
            ['key' => 'facon_rate_per_kg_carcass', 'value' => '1800', 'label' => 'Façon — tarif par kg carcasse',  'unit' => 'GNF/kg', 'display_order' => 42],
            ['key' => 'facon_min_fee',             'value' => '25000', 'label' => 'Façon — minimum forfaitaire / ordre', 'unit' => 'GNF', 'display_order' => 43],
        ];

        foreach ($tarifs as $s) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'abattoir', 'key' => $s['key'], 'farm_id' => null],
                array_merge($s, [
                    'group'        => 'abattoir',
                    'type'         => 'number',
                    'is_sensitive' => false,
                    'description'  => 'Prestation d\'abattage à façon (E8) — tarifs indicatifs à ajuster',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('service_sale_id');
            $t->dropColumn(['service_type', 'billing_model', 'billing_rate', 'service_fee']);
        });

        DB::table('settings')->where('group', 'abattoir')
            ->whereIn('key', ['facon_rate_per_bird', 'facon_rate_per_kg_live', 'facon_rate_per_kg_carcass', 'facon_min_fee'])
            ->delete();
    }
};
