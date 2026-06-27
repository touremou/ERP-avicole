<?php

use App\Models\Client;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('x-back auto-résout le parent {préfixe}.index sur les pages create', function () {
    $this->get(route('clients.create'))->assertOk()
        ->assertSee('href="' . route('clients.index') . '"', false);

    $this->get(route('purchases.create'))->assertOk()
        ->assertSee('href="' . route('purchases.index') . '"', false);
});

test('x-back honore un :to explicite (edit → fiche, pas la liste)', function () {
    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-BACK', 'name' => 'Back Test',
        'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);

    $this->get(route('clients.edit', $client))->assertOk()
        ->assertSee('href="' . route('clients.show', $client) . '"', false);

    $this->get(route('clients.statement', $client))->assertOk()
        ->assertSee('href="' . route('clients.show', $client) . '"', false);
});
