<?php

namespace App\Services\Discrepancy;

/**
 * Résultat de l'évaluation d'écart d'UNE ligne (expédition ↔ réception).
 *
 * Objet immuable produit par DiscrepancyEvaluator::evaluateLine().
 */
final class LineEvaluation
{
    public function __construct(
        public readonly string $productType,
        public readonly float $dispatched,
        public readonly float $received,
        public readonly float $damaged,
        public readonly float $missing,
        public readonly float $tolerance,        // % admis pour ce type
        public readonly float $lineRate,         // % manquant / expédié
        public readonly bool $withinTolerance,   // false → ligne critique
    ) {}

    /** Un écart existe si du manquant OU de l'endommagé est constaté. */
    public function hasDiscrepancy(): bool
    {
        return $this->missing > 0 || $this->damaged > 0;
    }
}
