<?php

namespace App\Services;

use App\Models\Stock;

/**
 * Source de vérité UNIQUE des conversions d'unités métier.
 *
 * Historiquement, les facteurs de conversion (1 sac = 50 kg, 1 alvéole = 30
 * œufs) étaient réécrits en dur dans une dizaine d'endroits (contrôleurs,
 * modèles, helpers), au risque d'incohérences si le paramétrage de la ferme
 * changeait. Ce service centralise ces conversions et lit les facteurs depuis
 * les Réglages (Paramètres › Général) :
 *
 *   - general.feed_bag_weight  (défaut 50) — poids standard d'un sac d'aliment
 *   - general.eggs_per_tray    (défaut 30) — nombre d'œufs par alvéole
 *
 * Un facteur peut être surchargé ponctuellement (ex. un achat en sacs de 25 kg
 * via metadata.bag_weight) ; sinon on retombe sur le paramètre global.
 */
class UnitConverter
{
    public const UNIT_SAC   = 'Sac';
    public const UNIT_KG    = 'KG';
    public const UNIT_TRAY  = 'Alvéole';
    public const UNIT_PIECE = 'Unité';

    /**
     * Poids d'un sac d'aliment en kg. Surcharge ponctuelle prioritaire sur le
     * paramètre global (un sac peut faire 25 kg sur un achat précis).
     */
    public static function bagWeight(?float $override = null): float
    {
        if ($override !== null && $override > 0) {
            return (float) $override;
        }

        return (float) setting('general.feed_bag_weight', 50) ?: 50.0;
    }

    /**
     * Nombre d'œufs par alvéole (plateau).
     */
    public static function eggsPerTray(): int
    {
        return (int) setting('general.eggs_per_tray', 30) ?: 30;
    }

    // ─── Détection d'unité (tolérante à la casse / aux accents) ───────────────

    public static function isSac(?string $unit): bool
    {
        return strtolower(trim((string) $unit)) === 'sac';
    }

    public static function isEggPiece(?string $unit): bool
    {
        return in_array(strtolower(trim((string) $unit)), ['unité', 'unite', 'piece', 'pièce'], true);
    }

    // ─── Conversions aliment (sac ↔ kg) ───────────────────────────────────────

    public static function sacksToKg(float $sacks, ?float $bagWeight = null): float
    {
        return $sacks * self::bagWeight($bagWeight);
    }

    public static function kgToSacks(float $kg, ?float $bagWeight = null): float
    {
        $bw = self::bagWeight($bagWeight);

        return $bw > 0 ? round($kg / $bw, 1) : 0.0;
    }

    // ─── Conversions œufs (alvéole ↔ unité) ────────────────────────────────────

    public static function traysToEggs(float $trays): int
    {
        return (int) round($trays * self::eggsPerTray());
    }

    public static function eggsToTrays(float $eggs): float
    {
        $per = self::eggsPerTray();

        return $per > 0 ? round($eggs / $per, 3) : 0.0;
    }

    /**
     * Normalise une quantité saisie vers l'unité PIVOT de stockage de la
     * catégorie de stock :
     *   - conso / Aliment : KG       (Sac → × poids du sac)
     *   - oeufs           : Alvéole  (Unité → ÷ œufs par alvéole)
     *   - autres          : inchangé (litières, matériels…)
     *
     * @param float       $quantity  Quantité dans l'unité de saisie
     * @param string|null $inputUnit Unité saisie (Sac, KG, Unité, Alvéole…)
     * @param string      $category  Catégorie de stock (Stock::CAT_*)
     * @param float|null  $bagWeight Surcharge ponctuelle du poids du sac
     */
    public static function toStockBase(float $quantity, ?string $inputUnit, string $category, ?float $bagWeight = null): float
    {
        if (in_array($category, [Stock::CAT_CONSO, 'Aliment'], true)) {
            return self::isSac($inputUnit) ? self::sacksToKg($quantity, $bagWeight) : $quantity;
        }

        if ($category === Stock::CAT_OEUFS) {
            return self::isEggPiece($inputUnit) ? self::eggsToTrays($quantity) : $quantity;
        }

        return $quantity;
    }
}
