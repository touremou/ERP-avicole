<?php

use App\Services\BarcodeService;

/*
 * Codes-barres linéaires (SVG sans dépendance) — Code128 / Code39 / EAN-13,
 * avec repli sûr : une étiquette n'est jamais vide.
 */

test('render dispatche vers le bon format et produit un SVG', function () {
    foreach (['code128', 'code39', 'ean13'] as $format) {
        $svg = BarcodeService::render('P-001', $format);
        expect($svg)->toStartWith('<svg')
            ->and($svg)->toContain('<rect'); // au moins une barre
    }
});

test('Code39 encode l\'alphanumérique majuscule et affiche la valeur', function () {
    $svg = BarcodeService::code39Svg('OP-2026');
    expect($svg)->toContain('OP-2026')       // texte lisible sous le code
        ->and($svg)->toContain('<rect');
});

test('EAN-13 : détection de compatibilité (12 ou 13 chiffres)', function () {
    expect(BarcodeService::isEan13('590123412345'))->toBeTrue()    // 12
        ->and(BarcodeService::isEan13('5901234123457'))->toBeTrue() // 13
        ->and(BarcodeService::isEan13('P-001'))->toBeFalse()        // alphanumérique
        ->and(BarcodeService::isEan13('12345'))->toBeFalse();       // trop court
});

test('EAN-13 calcule et affiche la clé de contrôle (5901234123457)', function () {
    // Exemple canonique : 590123412345 → clé 7.
    $svg = BarcodeService::ean13Svg('590123412345');
    expect($svg)->toContain('5901234123457'); // 13e chiffre = clé calculée
});

test('render EAN-13 sur une valeur NON numérique retombe sur Code128 (jamais vide)', function () {
    // « P-001 » n'est pas EAN : le repli produit un Code128 lisible.
    $svg = BarcodeService::render('P-001', 'ean13');
    expect($svg)->toStartWith('<svg')
        ->and($svg)->toContain('P-001')  // Code128 affiche la valeur telle quelle
        ->and($svg)->toContain('<rect');
});

test('un format inconnu retombe sur Code128', function () {
    $svg = BarcodeService::render('X-9', 'inexistant');
    expect($svg)->toStartWith('<svg')->and($svg)->toContain('X-9');
});
