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

// ── AUTH/ME ENRICHI (home par rôle + gate hors-ligne) ──

it('me renvoie le rôle, la matrice de permissions, le scope fermes et server_time', function () {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => 'Pixel Test',
    ])->json('token');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'role'],
            'role' => ['slug', 'label'],
            'permissions',
            'scope' => ['farm_id', 'farms'],
            'server_time',
        ]);

    // Admin : toutes les lettres sur au moins un module actif.
    $permissions = $response->json('permissions');
    expect($permissions)->not->toBeEmpty();
    expect(collect($permissions)->flatten()->unique()->sort()->values()->all())
        ->toBe(['C', 'L', 'M', 'S']);

    // Le scope reflète la ferme fixée par le middleware farm.api.
    expect($response->json('scope.farm_id'))->toBe(App\Models\Farm::where('code', 'FT-001')->first()->id);
    expect($response->json('scope.farms.0.name'))->toBe('Ferme Test');
    expect($response->json('scope.farms.0.is_default'))->toBeTrue();
});

it('login renvoie server_time (le serveur fait foi pour le since de sync)', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => 'Pixel Test',
    ])->assertOk()->assertJsonStructure(['token', 'user', 'server_time']);
});

// ── GESTION DES APPAREILS (tokens par device) ──

it('liste les appareils connectés avec le marqueur current', function () {
    $login = fn (string $device) => $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => $device,
    ])->json('token');

    $tokenA = $login('Tecno-Spark-gardien');
    $tokenB = $login('Tablette-magasin');

    $response = $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/devices');

    $response->assertOk();
    $devices = collect($response->json('devices'));
    expect($devices)->toHaveCount(2);
    expect($devices->firstWhere('name', 'Tablette-magasin')['current'])->toBeTrue();
    expect($devices->firstWhere('name', 'Tecno-Spark-gardien')['current'])->toBeFalse();
});

it('révoque un autre appareil (téléphone perdu) : son token cesse de fonctionner', function () {
    $login = fn (string $device) => $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => $device,
    ])->json('token');

    $lostToken = $login('Telephone-perdu');
    $myToken   = $login('Mon-telephone');

    $lostId = $this->user->tokens()->where('name', 'Telephone-perdu')->first()->id;

    $this->withHeader('Authorization', "Bearer {$myToken}")
        ->deleteJson("/api/v1/devices/{$lostId}")
        ->assertOk();

    // Le token révoqué est refusé partout. (forgetGuards : sinon le guard
    // Sanctum résolu à la requête précédente reste en mémoire dans le test.)
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$lostToken}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it("refuse de révoquer l'appareil courant (la déconnexion est la seule voie)", function () {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => 'Mon-telephone',
    ])->json('token');

    $currentId = $this->user->tokens()->where('name', 'Mon-telephone')->first()->id;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/devices/{$currentId}")
        ->assertStatus(422);

    // Toujours authentifié.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

it("ne révoque jamais l'appareil d'un autre utilisateur (404, sans fuite)", function () {
    $other = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
    $otherTokenId = $other->createToken('Appareil-autrui')->accessToken->id;

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-terrain',
        'device_name' => 'Mon-telephone',
    ])->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/devices/{$otherTokenId}")
        ->assertNotFound();

    expect($other->tokens()->count())->toBe(1);
});
