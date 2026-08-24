<?php

namespace App\Services\Accounting;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CHIFFRE D'AFFAIRES DES VENTES D'UNE PÉRIODE — tel qu'il était À LA CLÔTURE.
 *
 * Un retour de marchandise DÉCRÉMENTE la ligne de vente d'origine (et la
 * supprime si le retour est total), pendant que les rapports sélectionnent les
 * ventes par leur DATE DE VENTE. Un retour de septembre réécrivait donc le
 * chiffre d'affaires de JUILLET.
 *
 * Mesuré : une vente de 5 000 000 GNF du 15 juillet, dont la moitié est rendue
 * le 16 août, faisait tomber le résultat de juillet à 2 500 000 — un mois clos,
 * peut-être déjà imprimé et transmis au promoteur.
 *
 * C'est le principe que cette base défend partout ailleurs — « supprimer une
 * source d'énergie ne doit pas RÉÉCRIRE le passé » — appliqué à la plus grosse
 * ligne de produits.
 *
 * ─── CE QU'ON NE CHANGE PAS ───
 *
 * Le geste de retour lui-même. La marchandise est revenue : le stock, le solde
 * client, le remboursement et le statut de paiement doivent bouger, et ils le
 * font correctement. Ce qui était faux, c'est la PÉRIODE à laquelle le rapport
 * imputait cette baisse.
 *
 * ─── LA RÈGLE ───
 *
 * Un retour appartient à la période où il a lieu. Le chiffre d'une période P
 * est donc celui des lignes de vente encore présentes, AUGMENTÉ des retours
 * portant sur des ventes de P mais survenus APRÈS P :
 *
 *     CA(P) = Σ lignes de vente (ventes datées dans P)
 *           + Σ retours (vente datée dans P, retour postérieur à P)
 *
 * Un retour survenu DANS la période reste déduit — vente et retour tombent tous
 * deux dans P, le net est juste.
 *
 * ─── LES RETOURS ANTÉRIEURS À L'INSTANTANÉ ───
 *
 * `sale_return_items.product_type` / `batch_id` n'existent que depuis la
 * migration du 19/08/2026. Les retours plus anciens sont réintégrés au TOTAL —
 * il est juste — mais sous un libellé distinct : les ranger au hasard dans une
 * catégorie serait pire que de dire qu'on ne sait pas.
 */
class PeriodRevenue
{
    /** Libellé des retours trop anciens pour porter leur catégorie. */
    public const LIBELLE_NON_VENTILE = 'Retours antérieurs (catégorie non tracée)';

    /**
     * Les frais de livraison facturés sont une recette — d'une autre nature que
     * la marchandise, donc sur leur propre ligne. Les fondre dans une catégorie
     * de produit fausserait la rentabilité de cette catégorie.
     */
    public const LIBELLE_LIVRAISON = 'Livraison facturée';

    /**
     * LAIT COLLECTÉ ET VALORISÉ SUR LA PÉRIODE — un STOCK, pas un revenu.
     *
     * La collecte de lait alimente l'article « Lait » du magasin
     * (MilkProductionController::syncStock, Stock::CAT_LAIT), et `lait` est un
     * type de vente ADOSSÉ AU STOCK (SaleItem::STOCK_TYPES) : les litres
     * ressortent donc par une vente, qui est le vrai fait générateur du revenu.
     *
     * Trois écrans ajoutaient pourtant cette valorisation AU-DESSUS des ventes —
     * le compte de résultat, le tableau de bord et la rentabilité par espèce.
     * Les mêmes litres comptaient deux fois : une fois traits, une fois vendus.
     * Le commentaire d'origine disait « pas de flux de vente dédié à ce stade » ;
     * il l'était quand il a été écrit, il ne l'est plus.
     *
     * Cette déclaration existe pour que le chiffre reste VISIBLE — une traite
     * non encore vendue est un stock réel, et le taire serait aussi faux que de
     * l'appeler chiffre d'affaires. Elle n'entre dans aucun total de recettes.
     */
    public static function milkCollectedValued(Carbon $from, Carbon $to): float
    {
        return (float) \App\Models\MilkProduction::whereBetween('production_date', [$from, $to])
            ->where('unit_price', '>', 0)
            ->sum(\Illuminate\Support\Facades\DB::raw('total_liters * unit_price'));
    }

    /**
     * TVA COLLECTÉE SUR LA PÉRIODE — encaissée pour l'État, hors recettes.
     *
     * `sales.tax_amount` était écrit par `recalculateTotals()` et lu NULLE PART,
     * sauf sur la facture individuelle. Aucun écran ne totalisait jamais ce que
     * l'exploitation avait collecté : l'argent entrait en caisse, ressortait du
     * chiffre d'affaires (à juste titre, depuis #310) — et n'apparaissait ensuite
     * dans aucun état.
     *
     * ─── CE CHIFFRE N'EST PAS LA TVA DUE ───
     *
     * La TVA à reverser vaut « collectée − déductible ». Or ni
     * `supplier_invoices` ni `expenses` ne portent le moindre champ de taxe :
     * l'application n'enregistre pas la TVA payée sur les achats, donc elle ne
     * peut pas calculer le net.
     *
     * On expose donc la seule moitié que la base connaît, en la NOMMANT pour ce
     * qu'elle est. Afficher « TVA à payer » sur une moitié de l'équation serait
     * pire que de ne rien afficher : cela fonderait une déclaration sur un
     * chiffre faux, toujours trop élevé.
     */
    public static function taxCollected(Carbon $from, Carbon $to): float
    {
        return round((float) Sale::query()
            ->whereIn('status', ['valide', 'livre'])
            ->whereBetween('sale_date', [$from, $to])
            ->sum('tax_amount'), 2);
    }

    /**
     * Chiffre d'affaires des ventes de la période, ventilé par type de produit.
     *
     * @return array<string, float>  product_type (ou libellé) => montant
     */
    public static function byProductType(Carbon $from, Carbon $to): array
    {
        /*
         * NET DE REMISE, HORS TVA — et la livraison à part.
         *
         * Cette méthode sommait `sale_items.total`, c'est-à-dire le brut AVANT
         * remise. Une remise accordée ne réduisait donc pas la recette
         * enregistrée : le compte de résultat annonçait plus que ce que le
         * client avait été facturé.
         *
         * Le tableau de bord, lui, sommait `sales.total_amount` — donc TVA
         * COMPRISE. Il comptait dans le chiffre d'affaires de la ferme un argent
         * qui appartient à l'État. Sur une facture à 18 %, l'écart entre les deux
         * écrans atteignait 112 000 GNF pour un million vendu.
         *
         * La règle retenue est celle de tous les référentiels : le chiffre
         * d'affaires est net des remises et EXCLUT la taxe collectée. Les frais
         * de livraison facturés sont bien une recette, mais d'une autre nature —
         * ils figurent sur leur propre ligne plutôt que gonfler la vente de
         * marchandise.
         *
         * La remise est portée par la VENTE, pas par la ligne : on la répartit au
         * prorata du poids de chaque type de produit dans le sous-total. Sans
         * cela, une remise sur une facture mixte s'imputerait arbitrairement.
         */
        $revenue = [];

        foreach (self::netRatiosBySale($from, $to) as $saleId => $vente) {
            foreach ($vente['lignes'] as $type => $brut) {
                $cle = $type ?: self::LIBELLE_NON_VENTILE;
                $revenue[$cle] = ($revenue[$cle] ?? 0.0) + round($brut * $vente['ratio'], 2);
            }

            if ($vente['livraison'] > 0) {
                $revenue[self::LIBELLE_LIVRAISON] =
                    ($revenue[self::LIBELLE_LIVRAISON] ?? 0.0) + $vente['livraison'];
            }
        }

        foreach (self::laterReturns($from, $to)->get() as $ligne) {
            $cle = $ligne->product_type ?: self::LIBELLE_NON_VENTILE;
            $revenue[$cle] = ($revenue[$cle] ?? 0.0) + (float) $ligne->total;
        }

        return array_map(fn ($v) => round($v, 2), $revenue);
    }

    /**
     * Par vente de la période : ses lignes brutes par type, son ratio net après
     * remise, et ses frais de livraison facturés.
     *
     * Le ratio vaut (sous-total − remise) ÷ sous-total. Il est neutre (1.0) sans
     * remise, ce qui laisse les ventes ordinaires strictement inchangées.
     *
     * @return array<int, array{lignes: array<string, float>, ratio: float, livraison: float}>
     */
    private static function netRatiosBySale(Carbon $from, Carbon $to): array
    {
        $ventes = Sale::query()
            ->whereIn('status', ['valide', 'livre'])
            ->whereBetween('sale_date', [$from, $to])
            ->get(['id', 'subtotal', 'discount_amount', 'delivery_fee']);

        if ($ventes->isEmpty()) {
            return [];
        }

        $lignes = SaleItem::whereIn('sale_id', $ventes->pluck('id'))
            ->selectRaw('sale_id, product_type, SUM(total) as total')
            ->groupBy('sale_id', 'product_type')
            ->get();

        $parVente = [];

        foreach ($ventes as $v) {
            $sousTotal = (float) $v->subtotal;
            $remise    = (float) $v->discount_amount;

            $parVente[$v->id] = [
                'lignes'    => [],
                // Un sous-total nul ne se prorate pas : on ne divise pas par zéro
                // pour une vente sans marchandise (livraison seule, par exemple).
                'ratio'     => $sousTotal > 0 ? max(0.0, ($sousTotal - $remise) / $sousTotal) : 1.0,
                'livraison' => (float) ($v->delivery_fee ?? 0),
            ];
        }

        foreach ($lignes as $l) {
            $parVente[$l->sale_id]['lignes'][(string) $l->product_type] = (float) $l->total;
        }

        return $parVente;
    }

    /**
     * Chiffre d'affaires des ventes de la période pour un ensemble de LOTS —
     * même reconstitution, autre clef de ventilation (rentabilité par espèce).
     *
     * @param  iterable<int>  $batchIds
     */
    public static function forBatches(iterable $batchIds, Carbon $from, Carbon $to): float
    {
        $ids = collect($batchIds)->all();

        if ($ids === []) {
            return 0.0;
        }

        /*
         * Même règle que `byProductType` : net de remise. Sans le prorata, la
         * rentabilité d'une espèce comptait un chiffre d'affaires que le client
         * n'a jamais payé — et la marge s'en trouvait flattée.
         */
        $ratios = self::netRatiosBySale($from, $to);

        $vendu = 0.0;

        foreach (SaleItem::whereIn('batch_id', $ids)
            ->whereHas('sale', fn ($q) => $q->whereIn('status', ['valide', 'livre'])
                ->whereBetween('sale_date', [$from, $to]))
            ->get(['sale_id', 'total']) as $ligne) {
            $vendu += (float) $ligne->total * ($ratios[$ligne->sale_id]['ratio'] ?? 1.0);
        }

        $vendu = round($vendu, 2);

        $rendusApres = (float) self::laterReturns($from, $to)
            ->whereIn('sale_return_items.batch_id', $ids)
            ->sum('sale_return_items.total');

        return $vendu + $rendusApres;
    }

    /**
     * Lignes de retour portant sur une vente de la période, mais survenues
     * APRÈS elle — celles, et seulement celles, qui réécrivaient le passé.
     */
    private static function laterReturns(Carbon $from, Carbon $to)
    {
        return SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->whereIn('sales.status', ['valide', 'livre'])
            ->whereBetween('sales.sale_date', [$from, $to])
            ->whereDate('sale_returns.return_date', '>', $to->toDateString())
            ->select('sale_return_items.*');
    }
}
