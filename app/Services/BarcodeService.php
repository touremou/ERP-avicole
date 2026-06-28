<?php

namespace App\Services;

/**
 * Génération de codes-barres Code128 (jeu B) en SVG, SANS dépendance externe.
 *
 * Complément du QR (QrCodeService) : le code-barres linéaire encode un
 * IDENTIFIANT COURT (code de lot, référence article…) pour l'intégration aux
 * lecteurs/POS, là où le QR encode l'URL de traçabilité complète.
 *
 * On reste sur Code128-B (ASCII 32–126), qui couvre les codes alphanumériques
 * usuels. Le rendu SVG s'intègre directement dans une page d'impression.
 */
class BarcodeService
{
    /** Largeurs des 107 motifs Code128 (6 modules : barre,espace,barre,espace,barre,espace). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const STOP    = 106;

    /**
     * Code-barres Code128-B en SVG.
     *
     * @param string $value     Donnée à encoder (ASCII 32–126).
     * @param int    $height    Hauteur des barres (px).
     * @param int    $module    Largeur d'un module (px).
     * @param bool   $showText  Afficher la valeur sous le code.
     */
    public static function code128Svg(string $value, int $height = 60, int $module = 2, bool $showText = true): string
    {
        // Restreindre au jeu imprimable supporté (sinon caractère ignoré).
        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
        if ($value === '') {
            $value = '0';
        }

        $codes = [self::START_B];
        $sum = self::START_B;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $v = ord($value[$i]) - 32; // Code128-B : ASCII 32 → valeur 0
            $codes[] = $v;
            $sum += $v * ($i + 1);
        }
        $codes[] = $sum % 103; // checksum
        $codes[] = self::STOP;

        // Construit la suite des largeurs de barres/espaces (barre en premier).
        $bars = '';
        foreach ($codes as $code) {
            $bars .= self::PATTERNS[$code];
        }

        $x = 0;
        $rects = '';
        $barWidthTotal = 0;
        foreach (str_split($bars) as $idx => $w) {
            $w = (int) $w * $module;
            if ($idx % 2 === 0) { // index pair = barre noire
                $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
            }
            $x += $w;
            $barWidthTotal = $x;
        }

        $totalH = $showText ? $height + 16 : $height;
        $text = $showText
            ? '<text x="' . ($barWidthTotal / 2) . '" y="' . ($height + 13) . '" text-anchor="middle" font-family="monospace" font-size="12" fill="#0f172a">' . e($value) . '</text>'
            : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $barWidthTotal . '" height="' . $totalH . '" '
            . 'viewBox="0 0 ' . $barWidthTotal . ' ' . $totalH . '" shape-rendering="crispEdges">'
            . '<rect width="' . $barWidthTotal . '" height="' . $totalH . '" fill="#fff"/>'
            . '<g fill="#000">' . $rects . '</g>' . $text . '</svg>';
    }
}
