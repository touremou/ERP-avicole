<?php

namespace App\Observers;

use App\Models\DailyCheck;
use App\Models\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Observer dédié au DailyCheck.
 *
 * Remplace le code dans DailyCheck::booted() pour garantir :
 * 1. Transaction explicite (fonctionne même hors controller)
 * 2. lockForUpdate() pour éviter les race conditions
 * 3. Validation business : effectif ne peut jamais devenir négatif
 * 4. Testabilité indépendante
 *
 * Enregistrement dans AppServiceProvider::boot() :
 *   \App\Models\DailyCheck::observe(\App\Observers\DailyCheckObserver::class);
 *
 * @see AUDIT_MODULE_LOTS.md — Section B-11
 */
class DailyCheckObserver
{
    /**
     * Après création d'un pointage : décrémenter l'effectif du lot.
     */
    public function created(DailyCheck $check): void
    {
        $this->applyImpact($check, 'create');
    }

    /**
     * Pendant la mise à jour : recalculer le différentiel d'impact.
     *
     * Note : on utilise `updating` (pas `updated`) pour pouvoir accéder
     * aux valeurs originales via getOriginal() avant qu'elles soient écrasées.
     */
    public function updating(DailyCheck $check): void
    {
        // Calcul du différentiel entre ancien et nouvel impact
        $oldImpact = $this->calculateImpactFromValues(
            (int) $check->getOriginal('mortality'),
            (int) $check->getOriginal('qty_quarantine_in'),
            (int) $check->getOriginal('qty_sorted_out'),
            (int) $check->getOriginal('qty_quarantine_out')
        );

        $newImpact = $check->calculateNetImpact();
        $diff = $newImpact - $oldImpact;

        if ($diff === 0) {
            return; // Pas de changement d'effectif
        }

        // On stocke le diff pour l'appliquer dans `updated`
        // (car dans `updating`, le save n'est pas encore fait)
        $check->_pendingQuantityDiff = $diff;
    }

    /**
     * Après mise à jour : appliquer le différentiel calculé dans `updating`.
     */
    public function updated(DailyCheck $check): void
    {
        $diff = $check->_pendingQuantityDiff ?? 0;

        if ($diff === 0) {
            return;
        }

        $this->adjustBatchQuantity($check->batch_id, -$diff, "Rectification pointage #{$check->id}");

        // Nettoyage de la propriété temporaire
        unset($check->_pendingQuantityDiff);
    }

    /**
     * Après suppression (y compris soft-delete) : restituer l'effectif.
     */
    public function deleted(DailyCheck $check): void
    {
        $this->applyImpact($check, 'delete');
    }

    /**
     * Si on restaure un pointage soft-deleted : re-décrémenter.
     */
    public function restored(DailyCheck $check): void
    {
        $this->applyImpact($check, 'create');
    }

    // ─────────────────────────────────────────────
    // MÉTHODES PRIVÉES
    // ─────────────────────────────────────────────

    /**
     * Applique l'impact d'un pointage sur l'effectif du lot.
     *
     * @param DailyCheck $check   Le pointage concerné
     * @param string     $action  'create' (décrémenter) ou 'delete' (restituer)
     */
    private function applyImpact(DailyCheck $check, string $action): void
    {
        $impact = $check->calculateNetImpact();

        if ($impact === 0) {
            return;
        }

        // create = on retire des oiseaux, delete = on les restitue
        $delta = ($action === 'create') ? -$impact : $impact;

        $this->adjustBatchQuantity($check->batch_id, $delta, ucfirst($action) . " pointage #{$check->id}");
    }

    /**
     * Ajuste current_quantity du lot de manière atomique et sécurisée.
     *
     * @param int    $batchId  ID du lot
     * @param int    $delta    Variation (négatif = retrait, positif = ajout)
     * @param string $context  Description pour les logs
     *
     * @throws \DomainException Si l'effectif deviendrait négatif
     */
    private function adjustBatchQuantity(int $batchId, int $delta, string $context): void
    {
        // La transaction peut déjà être ouverte par le controller.
        // DB::transaction() gère les transactions imbriquées via savepoints.
        DB::transaction(function () use ($batchId, $delta, $context) {
            // lockForUpdate empêche deux requêtes concurrentes de lire la même valeur
            $batch = Batch::lockForUpdate()->find($batchId);

            if (! $batch) {
                Log::error("[DailyCheckObserver] Lot #{$batchId} introuvable. Contexte : {$context}");
                return;
            }

            $newQuantity = $batch->current_quantity + $delta;

            // Garde-fou : l'effectif ne peut JAMAIS être négatif
            if ($newQuantity < 0) {
                Log::error(
                    "[DailyCheckObserver] Effectif négatif empêché sur lot {$batch->code}. " .
                    "Actuel: {$batch->current_quantity}, delta: {$delta}, contexte: {$context}"
                );

                throw new \DomainException(
                    "Opération impossible : l'effectif du lot {$batch->code} deviendrait négatif " .
                    "(actuel: {$batch->current_quantity}, impact: {$delta})."
                );
            }

            // Mise à jour directe sans passer par update() pour éviter de
            // déclencher le BatchObserver en boucle sur current_quantity
            DB::table('batches')
                ->where('id', $batchId)
                ->update([
                    'current_quantity' => $newQuantity,
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Calcule l'impact net à partir de valeurs brutes.
     */
    private function calculateImpactFromValues(
        int $mortality,
        int $quarantineIn,
        int $sortedOut,
        int $quarantineOut
    ): int {
        return ($mortality + $quarantineIn + $sortedOut) - $quarantineOut;
    }
}
