<?php

use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('les seuils d\'alerte élevage sont semés et lisibles via setting()', function () {
    expect((float) setting('elevage.daily_mortality_alert_pct'))->toBe(0.5)
        ->and((int) setting('elevage.cumulative_mortality_alert_pct'))->toBe(5)
        ->and((int) setting('elevage.lameness_alert_pct'))->toBe(5)
        ->and((int) setting('elevage.pecking_alert_pct'))->toBe(2)
        ->and((int) setting('elevage.sanitary_break_days'))->toBe(14);

    // Présents en base avec un libellé → éditables dans l'UI pilotée par données.
    expect(DB::table('settings')->where('group', 'elevage')->where('key', 'lameness_alert_pct')->value('label'))
        ->toBe('Seuil alerte boiterie (bien-être)');
});

test('les seuils élevage apparaissent dans l\'écran Paramètres › Élevage', function () {
    $this->actingAs($this->adminUser)
        ->get(route('settings.index', ['group' => 'elevage']))
        ->assertOk()
        ->assertSee('Seuil alerte mortalité quotidienne')
        ->assertSee('Durée du vide sanitaire');
});
