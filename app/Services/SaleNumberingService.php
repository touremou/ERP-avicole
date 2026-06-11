<?php

namespace App\Services;

use App\Models\Sale;

class SaleNumberingService
{
    /**
     * Génère la prochaine référence de vente.
     *
     * Format : BL-2026-000123 (bon de livraison)
     *          FAC-2026-000045 (facture)
     */
    public static function generate(string $type): string
    {
        // Préfixes pilotés par les paramètres (Paramètres > Ventes).
        $blPrefix  = setting('ventes.invoice_prefix_bl', 'BL');
        $tvaPrefix = setting('ventes.invoice_prefix_tva', 'FAC');

        $prefix = match ($type) {
            'facture'        => $tvaPrefix,
            'bon_livraison'  => $blPrefix,
            default          => $blPrefix,
        };

        $year = now()->format('Y');

        $lastNumber = Sale::where('reference', 'LIKE', "{$prefix}-{$year}-%")
            ->withTrashed()
            ->orderByDesc('id')
            ->value('reference');

        if ($lastNumber) {
            $sequence = (int) substr($lastNumber, -6) + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
    }
}
