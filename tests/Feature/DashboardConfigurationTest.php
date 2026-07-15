<?php

use App\Models\DashboardConfiguration;
use App\Models\User;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── Helper de visibilité ──────────────────────────────────────────────────────

test('tous les blocs sont visibles par défaut', function () {
    $this->actingAs($this->adminUser);

    expect(dashboard_block_visible('kpi_row'))->toBeTrue()
        ->and(dashboard_block_visible('financial'))->toBeTrue();
});

test('un bloc masqué dans la config n\'est plus visible', function () {
    DashboardConfiguration::create([
        'user_id'       => $this->adminUser->id,
        'hidden_blocks' => ['trends', 'financial'],
    ]);

    $this->actingAs($this->adminUser);

    expect(dashboard_block_visible('trends'))->toBeFalse()
        ->and(dashboard_block_visible('financial'))->toBeFalse()
        ->and(dashboard_block_visible('kpi_row'))->toBeTrue();
});

// ─── IHM de réglage ─────────────────────────────────────────────────────────

test('l\'écran de personnalisation s\'affiche', function () {
    $this->actingAs($this->adminUser)
        ->get(route('dashboard.config'))
        ->assertOk()
        ->assertSee('Performance technique')
        ->assertSee('Synthèse financière du mois');
});

test('enregistrer masque les blocs décochés', function () {
    // L'utilisateur ne coche que kpi_row et technical → le reste est masqué.
    $this->actingAs($this->adminUser)
        ->put(route('dashboard.config.update'), [
            'visible' => ['kpi_row', 'technical'],
        ])
        ->assertRedirect(route('dashboard'));

    $config = DashboardConfiguration::where('user_id', $this->adminUser->id)->first();

    expect($config)->not->toBeNull()
        ->and($config->hidden_blocks)->toContain('trends', 'financial', 'priority_alerts')
        ->and($config->hidden_blocks)->not->toContain('kpi_row')
        ->and($config->hidden_blocks)->not->toContain('technical');
});

test('la configuration est propre à chaque utilisateur', function () {
    DashboardConfiguration::create([
        'user_id'       => $this->adminUser->id,
        'hidden_blocks' => ['financial'],
    ]);

    // L'admin a masqué la finance…
    $this->actingAs($this->adminUser);
    expect(dashboard_block_visible('financial'))->toBeFalse();

    // …mais un autre utilisateur garde tout visible.
    $other = User::factory()->create(['role_id' => $this->adminUser->role_id]);
    $this->actingAs($other);
    expect(dashboard_block_visible('financial'))->toBeTrue();
});

// ─── Rendu du dashboard ───────────────────────────────────────────────────────

test('le dashboard se charge avec une configuration personnalisée', function () {
    DashboardConfiguration::create([
        'user_id'       => $this->adminUser->id,
        'hidden_blocks' => ['trends', 'kpi_row', 'control_center'],
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk();
});
