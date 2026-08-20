<?php

namespace App\Actions\EggProduction;

use App\Models\EggProduction;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use App\Services\UnitConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Action : RÉOUVERTURE du tri d'une collecte.
 *
 * Corriger la récolte brute d'une journée déjà triée casse la balance
 * « trié = collecté ». La répartition par calibre n'est alors plus connue : la
 * seule suite cohérente est de défaire le tri et de le refaire.
 *
 * Cette action est donc le pendant exact de GradeEggProduction :
 *
 *   • elle SORT du stock ce que le tri y avait fait entrer (calibres et pertes) ;
 *   • elle REMET les calibres à zéro — sans quoi le prochain tri calculerait son
 *     delta contre des quantités que le stock ne porte plus, et créditerait moins
 *     que le compte réel ;
 *   • elle repasse `is_graded` à false, ce qui fait revenir la journée en
 *     réserve brute, prête à être recalibrée.
 *
 * ─── CE QU'ELLE REFUSE ───
 *
 * Si les œufs triés ont déjà quitté le magasin (vente, expédition), on ne peut
 * pas les en retirer : la sortie rendrait le stock négatif, c'est-à-dire faux.
 * On refuse alors la correction en le disant, plutôt que d'écrire un stock
 * impossible. C'est la garde que `destroy()` appliquait déjà pour l'annulation
 * d'une collecte ; elle vaut pour la même raison ici.
 */
class UngradeEggProduction
{
    public function execute(EggProduction $prod, string $motif = 'correction de la récolte'): EggProduction
    {
        if (! $prod->is_graded) {
            return $prod;
        }

        $this->assertStockCanAbsorb($prod);

        return DB::transaction(function () use ($prod, $motif) {
            $remiseAZero = [];

            foreach (array_map('strtolower', EggProduction::gradeCodes()) as $g) {
                $qty = (float) ($prod->{"grade_{$g}"} ?? 0);
                $remiseAZero["grade_{$g}"] = 0;

                if ($qty > 0) {
                    StockIntegrationService::syncMovement(
                        strtoupper($g),
                        'oeufs',
                        $qty,
                        'out',
                        "Réouverture du tri #{$prod->id} ({$motif}) — calibre " . strtoupper($g),
                        'Alvéole'
                    );
                }
            }

            foreach (self::lossMap() as $field => $stockName) {
                $qtyAlv = UnitConverter::eggsToTrays((float) ($prod->$field ?? 0));

                if ($qtyAlv > 0) {
                    StockIntegrationService::syncMovement(
                        $stockName,
                        'oeufs',
                        $qtyAlv,
                        'out',
                        "Réouverture du tri #{$prod->id} ({$motif}) — pertes",
                        'Alvéole'
                    );
                }
            }

            $prod->update(array_merge($remiseAZero, ['is_graded' => false]));

            return $prod->fresh();
        });
    }

    /** Les deux natures de perte, et l'article de stock qui les porte. */
    public static function lossMap(): array
    {
        return ['broken_eggs' => 'Cassé', 'small_eggs' => 'Anomalie'];
    }

    /**
     * Le stock doit pouvoir absorber la sortie, calibre par calibre.
     *
     * On parcourt les calibres CONFIGURÉS (`gradeCodes()`), et non une liste
     * écrite en dur : une exploitation qui ajoute un calibre dans les Réglages
     * doit le voir vérifié comme les autres.
     */
    private function assertStockCanAbsorb(EggProduction $prod): void
    {
        foreach (array_map('strtolower', EggProduction::gradeCodes()) as $g) {
            $qty = (float) ($prod->{"grade_{$g}"} ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $enStock = (float) (Stock::where('item_name', strtoupper($g))
                ->where('category', Stock::CAT_OEUFS)
                ->value('current_quantity') ?? 0);

            if ($enStock + 0.0001 < $qty) {
                throw ValidationException::withMessages(['logic' => sprintf(
                    "Correction impossible : le calibre %s ne porte plus que %s alvéole(s) en magasin, "
                    . "alors que ce tri en avait fait entrer %s. Des œufs sont déjà partis (vente ou expédition) : "
                    . "les retirer rendrait le stock négatif. Corrigez d'abord la sortie concernée.",
                    strtoupper($g),
                    number_format($enStock, 2),
                    number_format($qty, 2),
                )]);
            }
        }
    }
}
