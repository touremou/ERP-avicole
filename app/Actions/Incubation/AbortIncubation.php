<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use App\Services\UnitConverter;
use Illuminate\Support\Facades\DB;

class AbortIncubation
{
    public function execute(Incubation $incubation): void
    {
        DB::transaction(function () use ($incubation) {
            $incubateur = $incubation->incubator;

            /*
             * ABANDONNER UN CYCLE REND LES ŒUFS AU MAGASIN.
             *
             * Symétrie du prélèvement fait à la mise à couver : les œufs n'ont
             * pas été couvés, ils retournent d'où ils viennent. Sans ce retour,
             * abandonner un cycle ferait disparaître le stock pour de bon — la
             * même asymétrie que `CancelSale` évite en restockant une vente
             * annulée.
             *
             * Uniquement pour l'INTERNE : des œufs achetés n'étaient jamais
             * entrés au magasin, les y ajouter créerait un stock qui n'a jamais
             * existé.
             */
            if ($incubation->source_type === 'internal' && $incubation->egg_grade) {
                StockIntegrationService::syncMovement(
                    $incubation->egg_grade,
                    Stock::CAT_OEUFS,
                    UnitConverter::toStockBase((float) $incubation->eggs_count, 'Unité', Stock::CAT_OEUFS),
                    'in',
                    "Abandon de la mise à couver {$incubation->code_incubation} — retour au magasin",
                    'Alvéole',
                );
            }

            $incubation->delete();

            /*
             * On libère APRÈS la suppression, et seulement si la machine est vide.
             *
             * Avant : le statut passait à « Disponible » sans condition, et AVANT que
             * ce cycle ne soit supprimé — la machine était donc déclarée libre alors
             * qu'un autre cycle pouvait encore y être en incubation (multi-étages).
             */
            if ($incubateur && $incubateur->eggsInIncubation() === 0) {
                $incubateur->update(['status' => 'Disponible']);
            }
        });
    }
}
