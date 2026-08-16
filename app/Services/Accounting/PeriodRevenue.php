<?php

namespace App\Services\Accounting;

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
     * Chiffre d'affaires des ventes de la période, ventilé par type de produit.
     *
     * @return array<string, float>  product_type (ou libellé) => montant
     */
    public static function byProductType(Carbon $from, Carbon $to): array
    {
        $revenue = SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->whereIn('status', ['valide', 'livre'])
                ->whereBetween('sale_date', [$from, $to]))
            ->selectRaw('product_type, SUM(total) as total')
            ->groupBy('product_type')
            ->pluck('total', 'product_type')
            ->map(fn ($v) => (float) $v)
            ->all();

        foreach (self::laterReturns($from, $to)->get() as $ligne) {
            $cle = $ligne->product_type ?: self::LIBELLE_NON_VENTILE;
            $revenue[$cle] = ($revenue[$cle] ?? 0.0) + (float) $ligne->total;
        }

        return $revenue;
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

        $vendu = (float) SaleItem::whereIn('batch_id', $ids)
            ->whereHas('sale', fn ($q) => $q->whereIn('status', ['valide', 'livre'])
                ->whereBetween('sale_date', [$from, $to]))
            ->sum('total');

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
