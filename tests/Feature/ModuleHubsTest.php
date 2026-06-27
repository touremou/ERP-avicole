<?php

use App\Models\Module;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('Planning et Notifications sont exclus du lanceur de modules (méga-menu)', function () {
    expect(Module::nonLauncherSlugs())->toContain('planning')->toContain('notifications')
        ->and(Module::nonLauncherSlugs())->not->toContain('elevage')   // les modules métier restent
        ->and(Module::nonLauncherSlugs())->not->toContain('commerce');
});

test('les modules atterrissent sur leur hub', function () {
    expect(Module::landingRoute('elevage'))->toBe('elevage.index')
        ->and(Module::landingRoute('production'))->toBe('productions.index')
        ->and(Module::landingRoute('annuaire'))->toBe('annuaire.index')
        ->and(Module::landingRoute('logistique'))->toBe('logistique.index')
        ->and(Module::landingRoute('depenses'))->toBe('finance.index')
        ->and(Module::landingRoute('commerce'))->toBe('commerce.index');
});

test('les sous-pages portent une ancre de retour vers le hub du module', function () {
    // Page de sous-section → flèche retour vers le tableau de bord du module.
    $this->get(route('buildings.index'))->assertOk()
        ->assertSee('href="' . route('elevage.index') . '"', false);
    $this->get(route('expenses.index'))->assertOk()
        ->assertSee('href="' . route('finance.index') . '"', false);
    $this->get(route('egg-productions.index'))->assertOk()
        ->assertSee('href="' . route('productions.index') . '"', false);
});

test('le hub Logistique répond et expose ses KPIs', function () {
    $kpis = $this->get(route('logistique.index'))->assertOk()->assertSee('Logistique')->viewData('kpis');
    expect($kpis)->toHaveKeys(['references', 'stock_value', 'low', 'shrinkage']);
});

test('le hub Élevage répond et expose ses KPIs', function () {
    $kpis = $this->get(route('elevage.index'))->assertOk()->assertSee('Élevage')->viewData('kpis');
    expect($kpis)->toHaveKeys(['buildings', 'active_lots', 'livestock', 'critical']);
});

test('le hub Production répond et expose ses KPIs', function () {
    $kpis = $this->get(route('productions.index'))->assertOk()->assertSee('Production')->viewData('kpis');
    expect($kpis)->toHaveKeys(['eggs_today', 'eggs_month', 'milk_today', 'incub_open']);
});

test('le hub Annuaire répond et expose ses KPIs', function () {
    $kpis = $this->get(route('annuaire.index'))->assertOk()->viewData('kpis');
    expect($kpis)->toHaveKeys(['headcount', 'present', 'payroll', 'providers']);
});

test('les hubs Provenderie/Cultures/Abattoir donnent accès à leurs sous-sections', function () {
    $this->get(route('provenderie.dashboard'))->assertOk()
        ->assertSee('href="' . route('raw-materials.index') . '"', false)
        ->assertSee('href="' . route('formulas.index') . '"', false);

    $this->get(route('cultures.dashboard'))->assertOk()
        ->assertSee('href="' . route('plots.index') . '"', false)
        ->assertSee('href="' . route('crop-reports.index') . '"', false);

    $this->get(route('slaughter.dashboard'))->assertOk()
        ->assertSee('href="' . route('slaughter.finished') . '"', false);
});

test("le hub Production n'entre pas en collision avec la Provenderie (préfixe 'production.')", function () {
    // 'productions.index' doit résoudre le module production, pas provenderie.
    expect(Module::routePrefixMap())
        ->toHaveKey('productions.')
        ->and(Module::routePrefixMap()['productions.'])->toBe('production')
        ->and(Module::routePrefixMap()['production.'])->toBe('provenderie');
});
