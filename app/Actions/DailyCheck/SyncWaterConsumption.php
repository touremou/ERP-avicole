<?php

namespace App\Actions\DailyCheck;

use App\Models\Batch;

/**
 * Action : imputation de la consommation d'eau d'un lot à sa citerne.
 *
 * La consommation d'eau saisie au pointage journalier (DailyCheck::water_consumed,
 * en litres) est déduite du niveau de la source d'eau desservant le bâtiment du
 * lot — citerne affectée, sinon source « par défaut » de la ferme
 * (cf. Building::resolveWaterSource()).
 *
 * La méthode gère la COMPENSATION sur le modèle de SyncManureCollection : on
 * applique le DELTA (nouveau − ancien), si bien qu'une rectification ou une
 * suppression de pointage réajuste le niveau sans jamais double-compter.
 *
 * Périmètre : seules les sources à capacité (citernes) voient leur niveau
 * varier ; une source réseau (SEEG) ou un forage sans cuve n'a pas de niveau à
 * décrémenter → l'action est alors sans effet (la traçabilité reste le pointage).
 */
class SyncWaterConsumption
{
    /**
     * @param  Batch  $batch      Lot d'origine (→ bâtiment → source d'eau).
     * @param  float  $oldLiters  Litres précédemment comptabilisés (0 à la création).
     * @param  float  $newLiters  Nouveaux litres consommés (0 si pointage supprimé).
     */
    public function execute(Batch $batch, float $oldLiters, float $newLiters): void
    {
        $delta = $newLiters - $oldLiters;

        // Marge anti-arrondi (water_consumed est en decimal:2).
        if (abs($delta) < 0.0001) {
            return;
        }

        $source = $batch->building?->resolveWaterSource();

        // Pas de source desservant ce bâtiment, ou source sans cuve (réseau /
        // forage sans capacité) → aucun niveau à décrémenter.
        if (! $source || ! $source->capacity_liters) {
            return;
        }

        $newLevel = max(0.0, (float) $source->current_level_liters - $delta);
        $percent  = $source->capacity_liters > 0
            ? min(100, $newLevel / (float) $source->capacity_liters * 100)
            : (float) $source->current_level_percent;

        $source->update([
            'current_level_liters'  => $newLevel,
            'current_level_percent' => $percent,
        ]);
    }
}
