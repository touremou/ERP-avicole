<?php

use App\Models\Setting;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

test('currency() renvoie la devise par défaut (GNF)', function () {
    expect(currency())->toBe('GNF');
});

test('money() formate le montant avec la devise courante', function () {
    expect(money(12345))->toBe('12 345 GNF')
        ->and(money(1234.5, 2))->toBe('1 234,50 GNF')
        ->and(money(0))->toBe('0 GNF');
});

test('changer le paramètre general.currency change l\'affichage partout', function () {
    Setting::set('general.currency', 'XOF');

    expect(currency())->toBe('XOF')
        ->and(money(1000))->toBe('1 000 XOF');
});
