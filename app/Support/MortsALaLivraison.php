<?php

namespace App\Support;

use App\Models\Provider;

/**
 * LES SUJETS MORTS À LA LIVRAISON (DOA) — qui paie, et où cela se lit.
 *
 * Décision de l'exploitation : trois règles, choisies selon le CONTRAT du
 * fournisseur (fiche fournisseur, `providers.doa_policy`) et figées sur le lot à
 * sa mise en place (`batches.doa_policy`) :
 *
 *   A. AVOIR — le fournisseur crédite les morts (note de crédit) : le lot ne
 *      paie que ses vivants. La règle la plus courante, et celle par défaut.
 *   B. RÉPARTITION — la facture reste entière : les vivants absorbent le prix
 *      des morts (coût unitaire = facture ÷ vivants).
 *   C. PERTE AU TRANSPORT — le lot paie ses vivants ; le prix des morts va à
 *      une charge dédiée (« Pertes au transport »), suivie par fournisseur pour
 *      juger couvoirs et transporteurs.
 *
 * Une déclaration, lue par la mise en lot (CreateBatch) et par le recalcul
 * d'un changement de prix (UpdateBatch).
 */
final class MortsALaLivraison
{
    public const AVOIR       = 'avoir';
    public const REPARTITION = 'repartition';
    public const PERTE       = 'perte';

    public const POLITIQUES = [
        self::AVOIR       => 'A — Avoir fournisseur : coût sur les vivants',
        self::REPARTITION => 'B — Facture entière : coût absorbé par les vivants',
        self::PERTE       => 'C — Charge « Pertes au transport »',
    ];

    /** La règle du contrat de ce fournisseur ; A à défaut. */
    public static function politiqueDe(?Provider $fournisseur): string
    {
        $politique = $fournisseur?->doa_policy;

        return array_key_exists((string) $politique, self::POLITIQUES) ? $politique : self::AVOIR;
    }

    /**
     * @return array{acquisition: float, perte: float}  Coût d'achat du lot, et
     *         charge « Pertes au transport » (règle C seulement).
     */
    public static function couts(int $vivants, int $morts, float $prix, ?string $politique): array
    {
        $morts = max(0, $morts);

        return match ($politique) {
            self::REPARTITION => ['acquisition' => round(($vivants + $morts) * $prix, 2), 'perte' => 0.0],
            self::PERTE       => ['acquisition' => round($vivants * $prix, 2), 'perte' => round($morts * $prix, 2)],
            default           => ['acquisition' => round($vivants * $prix, 2), 'perte' => 0.0],
        };
    }
}
