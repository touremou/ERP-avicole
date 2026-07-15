<?php

namespace App\Services\Discrepancy;

/**
 * DiscrepancyEvaluator — Moteur d'écart (Three-Way Matching)
 *
 * Source UNIQUE de la logique d'écart entre l'expédition (ferme) et la
 * réception (magasin). Avant ce moteur, la formule du manquant était
 * dupliquée dans ValidateReception, ReconciliationService et ReceptionItem,
 * et les seuils de sévérité étaient codés en dur. Tout est désormais
 * centralisé ici et paramétré via config/logistique.php.
 *
 * Formule fondamentale (manquant ne peut pas être négatif) :
 *      manquant = max(0, expédié − reçu − endommagé)
 *
 * Règles de sévérité (sur le taux d'écart global) :
 *      - ≥ 1 ligne hors tolérance        → critique
 *      - taux > seuil "critique"          → critique
 *      - taux > seuil "attention"         → attention
 *      - sinon                            → normal
 */
class DiscrepancyEvaluator
{
    /** Marge anti-arrondi (les quantités sont en decimal:2). */
    private const EPSILON = 1e-6;

    /**
     * Tolérance admise (%) pour un type de produit, résolue via
     * config/logistique.php → setting() runtime → valeur de repli.
     */
    public function toleranceFor(string $productType): float
    {
        $map = (array) config('logistique.tolerances', []);
        $entry = $map[$productType] ?? $map['default'] ?? null;

        return $this->resolve($entry, 1.0);
    }

    /**
     * Évalue l'écart d'une seule ligne.
     */
    public function evaluateLine(string $productType, float $dispatched, float $received, float $damaged = 0.0): LineEvaluation
    {
        $missing   = max(0.0, $dispatched - $received - $damaged);
        $tolerance = $this->toleranceFor($productType);
        $lineRate  = $dispatched > 0 ? ($missing / $dispatched) * 100 : 0.0;

        return new LineEvaluation(
            productType:     $productType,
            dispatched:      $dispatched,
            received:        $received,
            damaged:         $damaged,
            missing:         $missing,
            tolerance:       $tolerance,
            lineRate:        round($lineRate, 2),
            withinTolerance: $lineRate <= $tolerance + self::EPSILON,
        );
    }

    /**
     * Évalue une réception complète et consolide les totaux + la sévérité.
     *
     * @param iterable<array{product_type:string,dispatched:float|int,received:float|int,damaged?:float|int}> $rawLines
     */
    public function evaluateReception(iterable $rawLines): ReceptionEvaluation
    {
        $lines = [];
        $totalDispatched = $totalReceived = $totalDamaged = $totalMissing = 0.0;
        $hasCritical = false;

        foreach ($rawLines as $raw) {
            $line = $this->evaluateLine(
                (string) $raw['product_type'],
                (float) $raw['dispatched'],
                (float) $raw['received'],
                (float) ($raw['damaged'] ?? 0),
            );

            $lines[]          = $line;
            $totalDispatched += $line->dispatched;
            $totalReceived   += $line->received;
            $totalDamaged    += $line->damaged;
            $totalMissing    += $line->missing;

            if (! $line->withinTolerance) {
                $hasCritical = true;
            }
        }

        $rate = $totalDispatched > 0
            ? round(($totalMissing / $totalDispatched) * 100, 2)
            : 0.0;

        return new ReceptionEvaluation(
            lines:           $lines,
            totalDispatched: $totalDispatched,
            totalReceived:   $totalReceived,
            totalDamaged:    $totalDamaged,
            totalMissing:    $totalMissing,
            discrepancyRate: $rate,
            severity:        $this->severityFor($rate, $hasCritical),
            hasCritical:     $hasCritical,
        );
    }

    /**
     * Classe la sévérité d'un taux d'écart global.
     */
    public function severityFor(float $rate, bool $hasCritical = false): string
    {
        $attention = $this->severityThreshold('attention', 2.0);
        $critique  = $this->severityThreshold('critique', 5.0);

        return match (true) {
            $hasCritical || $rate > $critique => 'critique',
            $rate > $attention                => 'attention',
            default                           => 'normal',
        };
    }

    /** Seuil de sévérité (%) résolu via config → setting() → repli. */
    private function severityThreshold(string $band, float $fallback): float
    {
        $entry = (array) config("logistique.severity.$band", []);

        return $this->resolve($entry ?: null, $fallback);
    }

    /**
     * Résout une entrée de config ['setting' => ..., 'default' => ...] en
     * valeur effective : paramètre runtime s'il existe, sinon repli.
     */
    private function resolve(?array $entry, float $fallback): float
    {
        if ($entry === null) {
            return $fallback;
        }

        $default = (float) ($entry['default'] ?? $fallback);

        return isset($entry['setting'])
            ? (float) setting($entry['setting'], $default)
            : $default;
    }
}
