<?php

namespace App\Support;

/**
 * Configuration d'impression des étiquettes (Réglages > Étiquettes + override
 * par querystring). Centralisé pour les 3 gabarits (générique, œufs, lot).
 *
 * Le NOMBRE d'étiquettes par page est AUTOMATIQUE : les étiquettes ont une
 * largeur physique fixe (mm) et se répartissent en grille selon le format de
 * page choisi (@page size) — le navigateur pagine tout seul.
 */
class LabelConfig
{
    /** Formats de page → dimension CSS @page. */
    public const PAGE_SIZES = [
        'seule' => 'auto',  // une étiquette, page ajustée au contenu
        'a4'    => 'A4',
        'a5'    => 'A5',
        'a6'    => 'A6',
    ];

    /** Gabarits standards d'étiquette → [largeur mm, hauteur mm]. */
    public const PRESETS = [
        '90x50'   => [90, 50],
        '100x50'  => [100, 50],
        '105x148' => [105, 148],  // A6
        '70x37'   => [70, 37],    // planche A4 × 24
        '63.5x38' => [63.5, 38.1],// planche A4 × 21
        '38x21'   => [38, 21],
    ];

    /** @return array<string,mixed> */
    public static function current(): array
    {
        $request = request();

        $copies = (int) $request->input('copies', (int) setting('etiquettes.copies', 1));
        $copies = max(1, min(200, $copies));

        $format = (string) $request->input('format', setting('etiquettes.page_format', 'a4'));
        if (! array_key_exists($format, self::PAGE_SIZES)) {
            $format = 'a4';
        }
        // En page « seule », une seule étiquette est imprimée.
        if ($format === 'seule') {
            $copies = 1;
        }

        $symbology = (string) setting('etiquettes.symbology', 'qr');

        // Dimensions : gabarit standard, ou personnalisé (custom).
        $preset = (string) setting('etiquettes.label_preset', '90x50');
        if ($preset === 'custom') {
            $width  = max(20.0, min(210.0, (float) setting('etiquettes.label_width', 90)));
            $height = max(0.0, min(297.0, (float) setting('etiquettes.label_height', 0))); // 0 = auto
        } else {
            [$width, $height] = self::PRESETS[$preset] ?? self::PRESETS['90x50'];
        }
        $gap = max(0.0, min(20.0, (float) setting('etiquettes.label_gap', 4)));

        return [
            'copies'        => $copies,
            'format'        => $format,
            'pageSize'      => self::PAGE_SIZES[$format],
            'symbology'     => $symbology,
            'showQr'        => in_array($symbology, ['qr', 'both'], true),
            'showBarcode'   => in_array($symbology, ['barcode', 'both'], true),
            'showFarm'      => (bool) setting('etiquettes.show_farm', true),
            'showCaption'   => (bool) setting('etiquettes.show_caption', true),
            'showPrintedAt' => (bool) setting('etiquettes.show_printed_at', false),
            'autoprint'     => (bool) setting('etiquettes.autoprint', false),
            'labelWidth'    => $width,
            'labelHeight'   => $height,   // 0 = auto
            'labelGap'      => $gap,
        ];
    }
}
