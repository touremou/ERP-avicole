<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * COMPARE le niveau d'un stock d'œufs à son registre de mouvements. N'ÉCRIT RIEN.
 *
 * ─── CE QUE FAISAIT CETTE CLASSE, ET POURQUOI IL FALLAIT L'ARRÊTER ───
 *
 * Elle écrasait `stocks.current_quantity` avec la somme de TOUTE la production
 * enregistrée :
 *
 *     $stock->update(['current_quantity' => EggProduction::sum('grade_m')]);
 *
 * Or c'est la MÊME colonne que ValidateSale décrémente à chaque vente. Toute
 * sortie — vente, casse, transfert — était donc effacée du niveau de stock, et le
 * magasin réaffichait des œufs partis depuis des semaines. Mesuré : 100 alvéoles
 * produites, 30 vendues, il en reste 70 ; après passage, 100.
 *
 * Le pire n'était pas le bouton mais la tâche de nuit : la commande `stocks:sync`
 * portait le même calcul et tournait CHAQUE NUIT. L'écart se reconstituait donc
 * tout seul, indéfiniment, sans que personne ne l'ait demandé.
 *
 * ─── POURQUOI ON NE RÉPARE PAS, ON CONSTATE ───
 *
 * `current_quantity` est tenue de façon transactionnelle à chaque mouvement
 * (StockIntegrationService::syncMovement, appelé par le calibrage comme par la
 * vente). C'est elle la source de vérité ; la recalculer depuis une AUTRE source ne
 * peut qu'y introduire de l'erreur — c'est exactement ce qui s'est produit.
 *
 * Et le registre ne permet pas d'arbitrer : un mouvement de type `adjustment`
 * enregistre `abs($nouveau - $ancien)`, une valeur SANS SIGNE. Sa direction n'existe
 * que dans une note en texte libre. Un ajustement de 5 peut donc valoir +5 ou −5 ;
 * additionner les mouvements ne rend pas un chiffre décidable.
 *
 * On CONSTATE donc l'écart, et on laisse la décision à l'exploitation — qui dispose
 * pour cela du bon outil : l'inventaire physique (écran Production d'œufs), qui
 * écrit un ajustement en règle. Nommer un écart qu'on ne sait pas trancher vaut
 * mieux que le trancher au hasard chaque nuit.
 */
class CompareEggStockToLedger
{
    /**
     * @return array<int, array{item: string, unit: string, level: float, ledger: float,
     *                          gap: float, adjustments: int, decidable: bool}>
     */
    public function execute(?int $farmId = null): array
    {
        $stocks = Stock::withoutGlobalScopes()
            ->where('category', Stock::CAT_OEUFS)
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderBy('item_name')
            ->get();

        $report = [];

        foreach ($stocks as $stock) {
            $sums = DB::table('stock_movements')
                ->where('stock_id', $stock->id)
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END), 0)  as entrees,
                    COALESCE(SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) as sorties,
                    COALESCE(SUM(CASE WHEN type = 'adjustment' THEN 1 ELSE 0 END), 0) as ajustements
                ")
                ->first();

            $ledger = (float) $sums->entrees - (float) $sums->sorties;
            $level  = (float) $stock->current_quantity;

            $report[] = [
                'item'        => (string) $stock->item_name,
                'unit'        => (string) $stock->unit,
                'level'       => $level,
                'ledger'      => $ledger,
                'gap'         => round($level - $ledger, 3),
                'adjustments' => (int) $sums->ajustements,
                // Un seul ajustement suffit à rendre le registre non décidable : sa
                // direction n'est pas enregistrée. On le dit, plutôt que de
                // présenter l'écart comme une anomalie certaine.
                'decidable'   => ((int) $sums->ajustements) === 0,
            ];
        }

        return $report;
    }
}
