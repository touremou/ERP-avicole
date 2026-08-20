<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Support\Facades\DB;

/**
 * Action : Clôture d'un lot de production.
 *
 * Calcul de marge :
 * - Revenus = vente de réforme (effectif restant × prix de cession).
 *   Le CA œufs n'est pas rattaché au lot (stock mutualisé) : suivi au niveau ferme.
 * - Coûts = acquisition + alimentation + santé + coûts additionnels
 * - Marge = Revenus - Coûts
 *
 * Gestion bâtiment (corrige S-07) :
 * - Vide sanitaire déclenché UNIQUEMENT si plus aucun lot actif dans le bâtiment
 */
class CloseBatch
{
    /**
     * @param  Batch $batch  Le lot à clôturer
     * @param  array $data   Données validées depuis CloseBatchRequest
     * @return Batch Le lot clôturé
     */
    public function execute(Batch $batch, array $data): Batch
    {
        // Garde de machine à états (audit W1) : re-clôturer un lot Terminé
        // recalculerait et ÉCRASERAIT sa marge historique.
        if (! $batch->isActive()) {
            throw new \DomainException("Le lot {$batch->code} est déjà clôturé (statut : {$batch->status}).");
        }

        return DB::transaction(function () use ($batch, $data) {
            // ─── REVENUS ───
            // Vente de réforme : effectif restant valorisé au prix de cession.
            $sellingPrice = (float) $data['actual_sell_price_per_unit'];
            $sellingRevenue = $batch->current_quantity * $sellingPrice;

            // Le revenu des œufs n'est pas inclus ici : les œufs sont vendus
            // depuis un stock mutualisé (module Stock/Ventes) sans rattachement
            // au lot d'origine. Le CA œufs est donc suivi globalement au niveau
            // ferme et non par lot (limite assumée du modèle de données).
            $totalRevenue = $sellingRevenue;

            // ─── COÛTS ───
            // Frais annexes : saisis dans le formulaire de clôture (main d'œuvre,
            // transport, divers). On retombe sur la valeur déjà enregistrée sur le
            // lot si le champ n'est pas soumis, pour ne pas l'écraser.
            $additionalCosts = array_key_exists('additional_costs', $data)
                ? (float) $data['additional_costs']
                : (float) ($batch->additional_costs ?? 0);

            $acquisitionCost = (float) ($batch->total_acquisition_cost ?? 0);

            /*
             * ALIMENT : ce que le lot a MANGÉ, pas ce qu'on a acheté pour lui.
             *
             * La marge enregistrée retenait la somme des ACHATS rattachés au lot.
             * Un sac livré la veille de la clôture et encore au silo lui était donc
             * imputé en entier, et la marge s'en trouvait amputée d'un aliment qui
             * nourrira le lot suivant. C'est un coût de STOCK, pas un coût de
             * revient.
             *
             * Surtout, l'écran de clôture affichait, lui, une estimation fondée sur
             * la consommation : le promoteur fixait son prix de vente en lisant une
             * marge, et le système en enregistrait une autre. Les deux passent
             * désormais par la même déclaration.
             *
             * EAU + ÉNERGIE entrent au passage dans la marge : l'écran les affichait
             * déjà dans les coûts connus, le calcul enregistré les ignorait.
             */
            $feedCost = (float) $batch->feed_cogs;
            $utilityCost = (float) $batch->utility_cost;
            $healthCost = (float) $batch->healthChecks()->sum('cost');
            $totalCost = $acquisitionCost + $feedCost + $healthCost + $utilityCost + $additionalCosts;

            // ─── MARGE ───
            $margin = $totalRevenue - $totalCost;

            // ─── MISE À JOUR DU LOT ───
            $batch->update([
                'status'                     => Batch::STATUS_TERMINE,
                'current_quantity'           => 0,
                'closing_date'               => $data['closing_date'],
                'actual_sell_price_per_unit'  => $sellingPrice,
                'additional_costs'           => $additionalCosts,
                'total_revenue'              => $totalRevenue,
                'margin'                     => $margin,
                'observations'               => trim(
                    ($batch->observations ?? '') . "\n" .
                    ($data['observations'] ?? '')
                ) ?: null,
            ]);

            // ─── GESTION BÂTIMENT ───
            $this->handleBuildingSanitaryBreak($batch);

            return $batch->fresh();
        });
    }

    /**
     * Déclenche le vide sanitaire UNIQUEMENT si plus aucun lot actif.
     *
     * Corrige S-07 : l'ancien code forçait la désinfection même si
     * d'autres lots étaient encore dans le bâtiment.
     */
    private function handleBuildingSanitaryBreak(Batch $batch): void
    {
        $building = $batch->building;
        if (! $building) {
            return;
        }

        $hasOtherActive = Batch::where('building_id', $building->id)
            ->where('id', '!=', $batch->id)
            ->active()
            ->exists();

        if (! $hasOtherActive) {
            $building->startSanitaryBreak();
        }
    }
}
