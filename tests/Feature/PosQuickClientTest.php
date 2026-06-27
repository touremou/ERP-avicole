<?php

use App\Models\CashRegisterSession;
use App\Models\Client;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('on peut créer un client depuis le POS (JSON) et il est réutilisable', function () {
    $resp = $this->actingAs($this->adminUser)->postJson(route('pos.clients.store'), [
        'name' => 'Boulangerie du Coin', 'phone' => '620999888', 'category' => 'grossiste',
    ])->assertOk();

    $id = $resp->json('id');
    expect($resp->json('name'))->toBe('Boulangerie du Coin');

    $client = Client::find($id);
    expect($client)->not->toBeNull()
        ->and($client->category)->toBe('grossiste')
        ->and($client->client_id)->toStartWith('CLI-')
        ->and($client->status)->toBe('actif');
});

test('le nom du client est requis', function () {
    $this->actingAs($this->adminUser)
        ->postJson(route('pos.clients.store'), ['phone' => '620000000'])
        ->assertStatus(422);
});

test('un utilisateur sans droit commerce.C ne peut pas créer de client au POS', function () {
    $viewer = \App\Models\User::factory()->create([
        'role_id' => \App\Models\Role::where('name', 'viewer')->value('id'),
    ]);

    $this->actingAs($viewer)
        ->postJson(route('pos.clients.store'), ['name' => 'X'])
        ->assertStatus(403);

    expect(Client::where('name', 'X')->exists())->toBeFalse();
});
