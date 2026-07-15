<?php

namespace App\Services\Discrepancy;

/**
 * Résultat consolidé de l'évaluation d'écart d'une RÉCEPTION complète.
 *
 * Objet immuable produit par DiscrepancyEvaluator::evaluateReception().
 */
final class ReceptionEvaluation
{
    /**
     * @param LineEvaluation[] $lines Évaluation ligne par ligne (ordre d'entrée préservé)
     */
    public function __construct(
        public readonly array $lines,
        public readonly float $totalDispatched,
        public readonly float $totalReceived,
        public readonly float $totalDamaged,
        public readonly float $totalMissing,
        public readonly float $discrepancyRate,   // % manquant / expédié, arrondi 2
        public readonly string $severity,         // normal | attention | critique
        public readonly bool $hasCritical,        // ≥ 1 ligne hors tolérance
    ) {}

    /** Un écart existe si du manquant OU de l'endommagé est constaté. */
    public function hasDiscrepancy(): bool
    {
        return $this->totalMissing > 0 || $this->totalDamaged > 0;
    }
}
