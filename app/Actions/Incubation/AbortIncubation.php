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
             *
             * ─── ET UNIQUEMENT SI LES ŒUFS SONT ENCORE DES ŒUFS ───
             *
             * « Les œufs n'ont pas été couvés » : la phrase ci-dessus énonce la
             * condition, le code ne la posait pas. Aucun test sur le statut —
             * seulement la provenance, qui reste vraie à vie.
             *
             * Or la même route sert DEUX gestes : la corbeille « Annuler
             * définitivement ce cycle ? » d'un cycle en cours, et la corbeille
             * « Supprimer cette archive ? » de l'HISTORIQUE, qui ne liste que des
             * cycles CLOS (IncubationController::index).
             *
             * Mesuré : cycle de 900 œufs calibre L (30 alvéoles), miré à 800
             * fertiles, éclos à 700 poussins. Magasin à 70 alvéoles. Supprimer
             * l'archive le remonte à 100 — trente alvéoles d'œufs qui sont
             * devenus des poussins, et qui depuis #305 sont VENDABLES.
             *
             * Une éclosion enregistrée consomme les œufs, quel qu'en soit le
             * résultat : un cycle qui n'éclôt RIEN a perdu ses œufs, il ne les
             * rend pas. C'est donc le statut qui tranche, pas le nombre de
             * poussins.
             */
            $encoreDesOeufs = $incubation->status !== 'clos';

            if ($encoreDesOeufs && $incubation->source_type === 'internal' && $incubation->egg_grade) {
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
