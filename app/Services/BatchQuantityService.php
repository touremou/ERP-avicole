<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service centralisé pour le calcul et la réconciliation des effectifs vivants.
 *
 * Source de vérité : current_quantity (décision architecture §2.1)
 *
 * Méthode : `initial_quantity − SUM(impacts daily_checks)` donne un MAJORANT de
 * l'effectif — il ignore les ventes, expéditions, abattages et transferts. La
 * réconciliation ne corrige donc QUE vers le bas : un effectif supérieur au
 * majorant est impossible ; un effectif inférieur est la situation normale d'un
 * lot qui a vendu.
 *
 * Cette dissymétrie n'est pas une précaution : sans elle, la tâche nocturne
 * ressuscitait chaque nuit les sujets vendus (mesuré : 500 − 100 vendus → 400,
 * puis remontés à 500).
 *
 * Ce service est utilisé par :
 * - La commande artisan `batches:rebuild-quantities`
 * - Le controller `BatchController::syncAllStocks` (refactoré)
 * - Les opérations de réouverture de lot
 *
 * @see AUDIT_MODULE_LOTS.md — Décision 2.1 et B-03/B-04
 */
class BatchQuantityService
{
    /**
     * Recalcule current_quantity pour un lot spécifique depuis ses daily_checks.
     *
     * Formule : current_quantity = initial_quantity - SUM(impacts nets)
     * Impact net = (mortality + quarantine_in + sorted_out) - quarantine_out
     *
     * @param  Batch $batch  Le lot à recalculer
     * @param  bool  $dryRun Si true, retourne le résultat sans modifier la DB
     * @param  bool  $allowRaise Autorise la REMONTÉE de l'effectif. Réservé à la
     *               réouverture d'un lot clos (cf. le commentaire ci-dessous) :
     *               une réconciliation ordinaire ne remonte jamais.
     * @return array Détails du recalcul ['old' => int, 'new' => int, 'corrected' => bool]
     */
    public function rebuildForBatch(Batch $batch, bool $dryRun = false, bool $allowRaise = false): array
    {
        // Calcul de l'impact total depuis les daily_checks non soft-deleted
        $totalImpact = DailyCheck::where('batch_id', $batch->id)
            ->whereNull('deleted_at')
            ->selectRaw('
                COALESCE(SUM(mortality), 0) 
                + COALESCE(SUM(qty_quarantine_in), 0) 
                + COALESCE(SUM(qty_sorted_out), 0) 
                - COALESCE(SUM(qty_quarantine_out), 0) 
                as net_impact
            ')
            ->value('net_impact') ?? 0;

        /*
         * CE CALCUL EST UN PLAFOND, PAS UNE VÉRITÉ — ET C'EST TOUT LE CORRECTIF.
         *
         * `initial_quantity − impacts des pointages` ignore TOUS les autres flux
         * sortants : ventes de sujets vifs (ValidateSale), expéditions
         * (CreateDispatch), dispatch de poussins, départs à l'abattoir,
         * transferts entre lots.
         *
         * Ce service traitait pourtant ce nombre comme la vérité et écrasait
         * `current_quantity` avec, dans les DEUX sens. Mesuré : un lot de 500
         * sujets dont 100 sont vendus tombe à 400 — puis la réconciliation le
         * REMONTE à 500. Les cent sujets vendus ressuscitaient.
         *
         * Et cette commande tourne SEULE, toutes les nuits
         * (`Schedule::command('batches:rebuild-quantities --force')->daily()`).
         * L'effectif est le nombre dont dépendent le taux de mortalité, le
         * dénominateur du taux de ponte, l'aliment par sujet et la marge de
         * clôture : le fausser fausse l'élevage entier.
         *
         * ─── LA RÈGLE, ET POURQUOI ELLE EST SÛRE ───
         *
         * `current_quantity` est décrémenté par CHAQUE sortie légitime. Le
         * calcul ci-dessus, qui n'en connaît qu'une partie, est donc un MAJORANT
         * de l'effectif possible :
         *
         *   • effectif > majorant  → impossible : la dérive est réelle, on
         *     corrige à la baisse. C'est exactement ce que ce service existe
         *     pour rattraper (mortalité pointée mais jamais décomptée) ;
         *   • effectif < majorant  → normal : les ventes et expéditions
         *     l'expliquent. On ne touche à RIEN.
         *
         * Cette formulation est immunisée contre une énumération incomplète des
         * flux sortants — précisément le défaut qu'elle corrige. Ajouter les
         * ventes au calcul aurait déplacé le problème sur le flux suivant qu'on
         * aurait oublié.
         *
         * ─── DEUX OPÉRATIONS, PAS UNE ───
         *
         * Ce service a deux appelants aux besoins OPPOSÉS, et les confondre est
         * ce qui a produit le défaut :
         *
         *   • RÉCONCILIER (tâche nocturne, stocks:sync) : ne doit JAMAIS
         *     remonter un effectif — les ventes expliquent l'écart ;
         *   • RESTAURER (ReopenBatch) : doit remonter, puisque la clôture a mis
         *     `current_quantity` à ZÉRO. Sans cela, un lot rouvert reste vide.
         *
         * `$allowRaise` rend cette intention explicite au point d'appel plutôt
         * que de la deviner. La valeur par défaut est le cas sûr.
         *
         * Le chiffre restauré reste un MAJORANT : il ne voit pas les ventes
         * antérieures à la clôture. La réouverture étant un acte délibéré et
         * privilégié (droit S), celui qui la déclenche peut corriger l'effectif —
         * ce qu'aucune tâche nocturne ne saurait faire.
         */
        $majorant = max(0, $batch->initial_quantity - (int) $totalImpact);
        $currentQuantity = (int) $batch->current_quantity;

        $expectedQuantity = $allowRaise ? $majorant : min($currentQuantity, $majorant);
        $needsCorrection = $currentQuantity !== $expectedQuantity;

        $result = [
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'initial_quantity' => $batch->initial_quantity,
            'total_impact' => (int) $totalImpact,
            'old_quantity' => $currentQuantity,
            'new_quantity' => $expectedQuantity,
            'drift' => $currentQuantity - $expectedQuantity,
            'corrected' => false,
        ];

        if ($needsCorrection && ! $dryRun) {
            // Mise à jour directe sans déclencher l'observer
            // (on ne veut pas que le BatchObserver envoie une alerte mortalité
            // pour une simple réconciliation)
            DB::table('batches')
                ->where('id', $batch->id)
                ->update([
                    'current_quantity' => $expectedQuantity,
                    'updated_at' => now(),
                ]);

            // Synchroniser qty_alive aussi (tant que la colonne existe)
            if (\Schema::hasColumn('batches', 'qty_alive')) {
                DB::table('batches')
                    ->where('id', $batch->id)
                    ->update(['qty_alive' => $expectedQuantity]);
            }

            $result['corrected'] = true;

            Log::info(
                "[BatchQuantityService] Lot {$batch->code} recalculé : " .
                "{$currentQuantity} → {$expectedQuantity} (drift: {$result['drift']})"
            );
        }

        return $result;
    }

    /**
     * Recalcule current_quantity pour TOUS les lots actifs.
     *
     * @param  bool  $dryRun  Si true, retourne le rapport sans modifier la DB
     * @return array Rapport global ['total_checked', 'total_corrected', 'details' => [...]]
     */
    public function rebuildAll(bool $dryRun = false): array
    {
        $batches = Batch::active()
            ->orderBy('code')
            ->get();

        $report = [
            'total_checked' => $batches->count(),
            'total_corrected' => 0,
            'total_drift' => 0,
            'details' => [],
        ];

        foreach ($batches as $batch) {
            $result = $this->rebuildForBatch($batch, $dryRun);

            if ($result['drift'] !== 0) {
                $report['details'][] = $result;
                $report['total_drift'] += abs($result['drift']);

                if ($result['corrected']) {
                    $report['total_corrected']++;
                }
            }
        }

        Log::info(
            "[BatchQuantityService] Rebuild complet : {$report['total_checked']} lots vérifiés, " .
            "{$report['total_corrected']} corrigés, drift total: {$report['total_drift']}"
        );

        return $report;
    }

    /**
     * Vérifie la cohérence d'un lot sans le modifier.
     *
     * Utile pour le dashboard : afficher un badge "⚠ Désync" si le lot est incohérent.
     *
     * @return bool True si current_quantity est cohérent avec les daily_checks
     */
    public function isConsistent(Batch $batch): bool
    {
        $result = $this->rebuildForBatch($batch, dryRun: true);
        return $result['drift'] === 0;
    }
}
