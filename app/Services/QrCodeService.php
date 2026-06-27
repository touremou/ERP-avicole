<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Génération de QR codes pour la traçabilité (étiquettes de lots, cartons
 * d'œufs…). Isole la dépendance endroid/qr-code derrière une API stable :
 * on renvoie un data-URI PNG directement intégrable dans une page HTML
 * d'impression ou un PDF, sans fichier temporaire.
 *
 * Niveau de correction d'erreur « Medium » : bon compromis lisibilité /
 * robustesse pour une étiquette qui peut être salie ou froissée en élevage.
 */
class QrCodeService
{
    /**
     * Retourne un QR code encodant $data sous forme de data-URI PNG.
     *
     * @param string $data Contenu encodé (typiquement une URL de traçabilité)
     * @param int    $size Côté du QR en pixels
     */
    public static function dataUri(string $data, int $size = 220): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 4,
        ))->build();

        return $result->getDataUri();
    }
}
