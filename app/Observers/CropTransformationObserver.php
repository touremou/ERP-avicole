<?php

namespace App\Observers;

use App\Models\CropTransformation;
use App\Models\Stock;
use App\Services\StockIntegrationService;

/**
 * SUPPRIMER UN LOT DE TRANSFORMATION NE REVERSAIT RIEN.
 *
 * Les deux entités sœurs du même module le font depuis un audit précédent :
 * `CropInputObserver::deleted` reverse l'entrée d'intrant, `HarvestObserver::deleted`
 * reverse l'entrée de récolte. La transformation était la seule des trois à
 * écrire le stock à la création sans jamais le défaire.
 *
 * ─── CE QUE LAISSAIT UNE SUPPRESSION ───
 *
 * Mesuré sur 200 kg de manioc transformés en 60 kg de gari, puis supprimés :
 *
 *   • le manioc reste à 300 kg — les 200 kg consommés ne reviennent jamais ;
 *   • le gari reste à 60 kg — un produit fini dont PLUS AUCUN lot de
 *     production n'existe : invendable de bonne foi, introuvable en
 *     traçabilité, et pourtant compté dans la valeur de l'inventaire ;
 *   • la récolte redevient « non engagée », donc re-transformable.
 *
 * Le troisième point rouvre par la suppression la porte qu'on venait de fermer
 * à l'enregistrement : supprimer puis re-transformer empile 60 kg de gari à
 * chaque tour, indéfiniment, sans jamais consommer un kilo de plus.
 *
 * ─── LA RÉOUVERTURE EST LÉGITIME, LE STOCK FANTÔME NON ───
 *
 * On ne bloque pas la suppression : corriger un lot saisi de travers est un
 * geste d'atelier normal, et la récolte DOIT redevenir transformable. Ce qui
 * manquait, c'est que le stock revienne au même moment dans son état d'avant.
 *
 * ─── PLAFONNEMENT À ZÉRO ───
 *
 * Le retrait du produit fini n'est PAS strict, comme les reversements sœurs :
 * si une partie du gari est déjà partie (vente, transfert), on retire ce qui
 * reste plutôt que de refuser une correction d'atelier. Refuser laisserait
 * l'utilisateur devant un lot faux qu'il ne peut pas effacer — et le pousserait
 * à le contourner.
 */
class CropTransformationObserver
{
    /**
     * CORRIGER LES QUANTITÉS D'UN LOT NE TOUCHAIT PAS LE STOCK.
     *
     * L'en-tête du contrôleur l'annonçait — « la re-synchronisation stock
     * n'est pas rejouée pour éviter les doublons ». Le motif est juste : REJOUER
     * la création doublerait. Mais les deux entités sœurs ne rejouent pas non
     * plus : elles annulent l'ANCIENNE valeur puis appliquent la nouvelle. Le
     * problème invoqué pour ne rien faire était déjà résolu à côté.
     *
     * Mesuré : un lot saisi 200 kg → 60 kg, corrigé en 400 kg → 600 kg. La
     * fiche affiche 400 et 600, ses deux drapeaux disent « synchronisé au
     * stock », et le stock en est resté à 200 consommés / 60 produits. La
     * fiche AFFIRME donc un stock qu'elle n'a pas écrit.
     *
     * Et la divergence se propage à la suppression, qui reverse d'après les
     * valeurs COURANTES : créer 200→60, corriger en 400→600, puis supprimer
     * rendait 400 kg de manioc pour 200 réellement consommés — 200 kg de
     * matière première créés de rien, sur un stock initial de 500.
     */
    public function updated(CropTransformation $transformation): void
    {
        if (! $transformation->wasChanged([
            'input_quantity', 'input_unit', 'input_stock_item',
            'output_quantity', 'output_unit', 'output_stock_item',
        ])) {
            return;   // ex. le seul drapeau posé juste après la création
        }

        $label = $transformation->batch_number ?: ('#' . $transformation->id);

        // ─── Matière première : rendre l'ancienne quantité, reprendre la nouvelle
        if ($transformation->getOriginal('consumed_from_stock')) {
            $oldName = trim((string) $transformation->getOriginal('input_stock_item'));
            $oldQty  = (float) $transformation->getOriginal('input_quantity');

            if ($oldName !== '' && $oldQty > 0) {
                StockIntegrationService::syncMovement(
                    $oldName, Stock::CAT_RECOLTES, $oldQty, 'in',
                    "Correction transformation {$label} (ancienne valeur annulée)",
                    $transformation->getOriginal('input_unit') ?: 'kg'
                );
            }
        }

        if ($transformation->consumed_from_stock) {
            $name = trim((string) $transformation->input_stock_item);
            $qty  = (float) $transformation->input_quantity;

            if ($name !== '' && $qty > 0) {
                StockIntegrationService::syncMovement(
                    $name, Stock::CAT_RECOLTES, $qty, 'out',
                    "Correction transformation {$label} (nouvelle valeur)",
                    $transformation->input_unit ?: 'kg'
                );
            }
        }

        // ─── Produit fini : retirer l'ancienne sortie, entrer la nouvelle
        if ($transformation->getOriginal('synced_to_stock')) {
            $oldName = trim((string) $transformation->getOriginal('output_stock_item'));
            $oldQty  = (float) $transformation->getOriginal('output_quantity');

            if ($oldName !== '' && $oldQty > 0) {
                StockIntegrationService::syncMovement(
                    $oldName, Stock::CAT_PRODUITS_FINIS, $oldQty, 'out',
                    "Correction transformation {$label} (ancienne valeur annulée)",
                    $transformation->getOriginal('output_unit') ?: 'kg'
                );
            }
        }

        if ($transformation->synced_to_stock) {
            $name = trim((string) $transformation->output_stock_item);
            $qty  = (float) $transformation->output_quantity;

            if ($name !== '' && $qty > 0) {
                StockIntegrationService::syncMovement(
                    $name, Stock::CAT_PRODUITS_FINIS, $qty, 'in',
                    "Correction transformation {$label} (nouvelle valeur)",
                    $transformation->output_unit ?: 'kg'
                );
            }
        }
    }

    public function deleted(CropTransformation $transformation): void
    {
        $label = $transformation->batch_number ?: ('#' . $transformation->id);

        // Retour de la matière première consommée.
        if ($transformation->consumed_from_stock) {
            $name = trim((string) $transformation->input_stock_item);
            $qty  = (float) $transformation->input_quantity;

            if ($name !== '' && $qty > 0) {
                StockIntegrationService::syncMovement(
                    $name, Stock::CAT_RECOLTES, $qty, 'in',
                    "Annulation transformation supprimée {$label}", $transformation->input_unit ?: 'kg'
                );
            }
        }

        // Retrait du produit fini qui n'a plus de lot de production.
        if ($transformation->synced_to_stock) {
            $name = trim((string) $transformation->output_stock_item);
            $qty  = (float) $transformation->output_quantity;

            if ($name !== '' && $qty > 0) {
                StockIntegrationService::syncMovement(
                    $name, Stock::CAT_PRODUITS_FINIS, $qty, 'out',
                    "Annulation transformation supprimée {$label}", $transformation->output_unit ?: 'kg'
                );
            }
        }
    }
}
