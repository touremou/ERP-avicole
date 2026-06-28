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
        ];
    }
}
