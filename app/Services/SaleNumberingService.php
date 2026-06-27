<?php

namespace App\Services;

class SaleNumberingService
{
    /**
     * Génère la prochaine référence de vente.
     *
     * Conservé pour rétro-compatibilité : délègue désormais au service de
     * numérotation centralisé (DocumentNumberingService), qui pilote préfixe
     * et séquence de façon uniforme pour tous les documents.
     *
     * Format : BL-2026-000123 (bon de livraison)
     *          FAC-2026-000045 (facture)
     */
    public static function generate(string $type): string
    {
        $documentType = match ($type) {
            'facture'       => 'sale_invoice',
            'bon_livraison' => 'sale_bl',
            default         => 'sale_bl',
        };

        return DocumentNumberingService::generate($documentType);
    }
}
