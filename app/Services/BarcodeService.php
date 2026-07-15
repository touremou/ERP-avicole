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
     * Point d'entrée unique : rend le code-barres au format choisi
     * (Réglages > Étiquettes > Format de code-barres). Repli sûr : un format
     * inconnu OU un EAN-13 sur une valeur non numérique retombe sur Code128,
     * de sorte qu'une étiquette n'est JAMAIS vide.
     *
     * @param string $value   Donnée à encoder.
     * @param string $format  code128 | code39 | ean13.
     */
    public static function render(string $value, string $format = 'code128', int $height = 60, int $module = 2, bool $showText = true): string
    {
        return match ($format) {
            'code39' => self::code39Svg($value, $height, $module, $showText),
            'ean13'  => self::isEan13($value)
                ? self::ean13Svg($value, $height, $module, $showText)
                : self::code128Svg($value, $height, $module, $showText),
            default  => self::code128Svg($value, $height, $module, $showText),
        };
    }

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

    // ── Code 39 ───────────────────────────────────────────────────────────

    /**
     * Table Code 39 : caractère → 9 éléments (barre,espace,… en alternance,
     * barre en premier), 'n' étroit / 'w' large. '*' = start/stop.
     */
    private const CODE39 = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    /**
     * Code 39 en SVG — alphanumérique MAJUSCULE + « -. $/+% », auto-vérifiant.
     * Lu nativement par la quasi-totalité des lecteurs, y compris bas de gamme.
     * Les caractères non supportés sont ignorés (jamais d'encodage invalide).
     */
    public static function code39Svg(string $value, int $height = 60, int $module = 2, bool $showText = true): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/[^0-9A-Z\-. $\/+%]/', '', $value) ?? '';
        if ($value === '') {
            $value = '0';
        }

        // Séquence complète : *  <chars>  * — séparés par un espace étroit.
        $chars = array_merge(['*'], str_split($value), ['*']);
        $narrow = $module;
        $wide = $module * 3;

        $x = 0;
        $rects = '';
        foreach ($chars as $ci => $char) {
            $pattern = self::CODE39[$char] ?? self::CODE39['*'];
            for ($i = 0; $i < 9; $i++) {
                $w = $pattern[$i] === 'w' ? $wide : $narrow;
                if ($i % 2 === 0) { // éléments pairs = barres
                    $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
                }
                $x += $w;
            }
            // Espace inter-caractères étroit (sauf après le dernier).
            if ($ci < count($chars) - 1) {
                $x += $narrow;
            }
        }

        return self::wrapSvg($rects, $x, $height, $showText ? $value : null);
    }

    // ── EAN-13 ────────────────────────────────────────────────────────────

    private const EAN_L = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];
    private const EAN_G = ['0100111','0110011','0011011','0100001','0011101','0111001','0000101','0010001','0001001','0010111'];
    private const EAN_R = ['1110010','1100110','1101100','1000010','1011100','1001110','1010000','1000100','1001000','1110100'];
    /** Parité des 6 chiffres de gauche selon le 1er chiffre (L=impair, G=pair). */
    private const EAN_PARITY = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];

    /** Vrai si $value peut être encodé en EAN-13 (12 ou 13 chiffres). */
    public static function isEan13(string $value): bool
    {
        $v = preg_replace('/\D/', '', $value) ?? '';
        return strlen($v) === 12 || strlen($v) === 13;
    }

    /** Clé de contrôle EAN-13 (modulo 10 sur les 12 premiers chiffres). */
    private static function ean13Check(string $digits12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits12[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - $sum % 10) % 10;
    }

    /**
     * EAN-13 en SVG — standard caisse/retail. Accepte 12 chiffres (la clé est
     * calculée) ou 13 (la clé fournie est ré-écrite pour garantir la validité).
     */
    public static function ean13Svg(string $value, int $height = 60, int $module = 2, bool $showText = true): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $base = substr(str_pad($digits, 12, '0', STR_PAD_LEFT), 0, 12);
        $full = $base . self::ean13Check($base);

        $first = (int) $full[0];
        $parity = self::EAN_PARITY[$first];

        // 101 (garde) + 6 gauche + 01010 (garde centrale) + 6 droite + 101.
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $d = (int) $full[$i];
            $bits .= $parity[$i - 1] === 'L' ? self::EAN_L[$d] : self::EAN_G[$d];
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::EAN_R[(int) $full[$i]];
        }
        $bits .= '101';

        $x = 0;
        $rects = '';
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i++) {
            if ($bits[$i] === '1') {
                $rects .= '<rect x="' . $x . '" y="0" width="' . $module . '" height="' . $height . '"/>';
            }
            $x += $module;
        }

        return self::wrapSvg($rects, $x, $height, $showText ? $full : null);
    }

    /** Enveloppe SVG commune (fond blanc + barres noires + texte optionnel). */
    private static function wrapSvg(string $rects, int|float $width, int $height, ?string $text): string
    {
        $totalH = $text !== null ? $height + 16 : $height;
        $label = $text !== null
            ? '<text x="' . ($width / 2) . '" y="' . ($height + 13) . '" text-anchor="middle" font-family="monospace" font-size="12" fill="#0f172a">' . e($text) . '</text>'
            : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $totalH . '" '
            . 'viewBox="0 0 ' . $width . ' ' . $totalH . '" shape-rendering="crispEdges">'
            . '<rect width="' . $width . '" height="' . $totalH . '" fill="#fff"/>'
            . '<g fill="#000">' . $rects . '</g>' . $label . '</svg>';
    }
}
