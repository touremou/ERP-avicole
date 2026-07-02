<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $admin = Role::firstOrCreate(
        ['name' => 'admin'],
        ['label' => 'Admin', 'display_name' => 'Admin', 'permissions' => ['L', 'C', 'M', 'S']]
    );

    $this->user = User::factory()->create([
        'role_id' => $admin->id,
        'password' => bcrypt('secret-terrain'),
    ]);

    // Le middleware farm.api borne l'API à la ferme de l'utilisateur : tout
    // utilisateur mobile DOIT être affecté (sinon repli mono-ferme par défaut).
    Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id'    => $farm->id,
        'user_id'    => $this->user->id,
        'is_default' => true,
        'is_owner'   => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('délivre un token Sanctum avec des identifiants valides', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => 'Pixel Test',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

    expect($this->user->tokens()->count())->toBe(1);
});

it('refuse des identifiants invalides', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'mauvais',
        'device_name' => 'Pixel Test',
    ])->assertUnprocessable();
});

it('refuse un compte désactivé', function () {
    $this->user->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => 'Pixel Test',
    ])->assertUnprocessable();
});

it('protège les endpoints par token', function () {
    $this->getJson('/api/v1/batches')->assertUnauthorized();
});

it('liste les lots actifs avec un token valide', function () {
    $building = Building::factory()->create();
    Batch::factory()->create(['building_id' => $building->id, 'status' => 'Actif']);

    $token = $this->user->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/batches');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('enregistre un pointage journalier via la porte de sync v1', function () {
    // Depuis la fusion A2, l'écriture terrain passe par /sync/push (opération
    // idempotente à uuid) — l'ancienne route POST /api/v1/daily-checks est morte.
    $building = Building::factory()->create();
    $batch = Batch::factory()->create([
        'building_id' => $building->id,
        'status' => 'Actif',
        'current_quantity' => 500,
    ]);

    $token = $this->user->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => fake()->uuid(),
            'type'    => 'daily_check.create',
            'payload' => [
                'uuid'           => fake()->uuid(),
                'batch_id'       => $batch->id,
                'check_date'     => now()->toDateString(),
                'mortality'      => 3,
                'feed_consumed'  => 0,
                'feed_type'      => 'Démarrage',
                'water_consumed' => 120,
            ],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('success');

    expect($batch->fresh()->current_quantity)->toBe(497);
});

it('révoque le token à la déconnexion', function () {
    $token = $this->user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($this->user->tokens()->count())->toBe(0);
});
