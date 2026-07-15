<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Expense;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Audit 360° §1.2-B2 : les garde-fous d'intégrité doivent exister EN BASE,
 * pas seulement dans le code (double-clic, replay réseau, accès concurrent).
 * Ces tests verrouillent les contraintes — si une migration future les
 * supprime par accident, la CI casse.
 */

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
});

// ─── Structure : les index d'idempotence sync sont UNIQUES ───

test('les uuid de synchro offline portent un index UNIQUE en base', function () {
    // sqlite (base de test) : PRAGMA expose le flag "unique" de chaque index.
    $expected = [
        'batches'         => 'batches_uuid_unique',
        'sales'           => 'sales_uuid_unique',
        'daily_checks'    => 'daily_checks_uuid_unique',
        'health_checks'   => 'health_checks_uuid_unique',
        'incubations'     => 'incubations_uuid_unique',
        'egg_productions' => 'egg_productions_uuid_unique',
    ];

    foreach ($expected as $table => $index) {
        $indexes = collect(DB::select("PRAGMA index_list({$table})"));

        $found = $indexes->first(fn ($i) => $i->name === $index);

        expect($found)->not->toBeNull("Index {$index} absent de {$table}");
        expect((int) $found->unique)->toBe(1, "Index {$index} n'est pas UNIQUE");
    }
});

// ─── Comportement : la base rejette physiquement les doublons ───

test('un second pointage le même jour pour le même lot est rejeté par la base', function () {
    $building = Building::factory()->create();
    $batch = Batch::factory()->create(['building_id' => $building->id, 'current_quantity' => 500]);

    DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => '2026-07-01',
        'mortality'  => 0,
    ]);

    expect(fn () => DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => '2026-07-01',
        'mortality'  => 0,
    ]))->toThrow(QueryException::class);
});

test('un uuid de pointage ne peut jamais être inséré deux fois (idempotence sync)', function () {
    $building = Building::factory()->create();
    $batch = Batch::factory()->create(['building_id' => $building->id, 'current_quantity' => 500]);
    $uuid = (string) Str::uuid();

    DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => '2026-07-01',
        'mortality'  => 0,
        'uuid'       => $uuid,
    ]);

    // Même uuid, jour différent : le métier l'autoriserait, la base non.
    expect(fn () => DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => '2026-07-02',
        'mortality'  => 0,
        'uuid'       => $uuid,
    ]))->toThrow(QueryException::class);
});

test('un uuid de dépense ne peut jamais être inséré deux fois', function () {
    $user = App\Models\User::factory()->create();
    $uuid = (string) Str::uuid();

    Expense::factory()->create(['uuid' => $uuid, 'user_id' => $user->id]);

    expect(fn () => Expense::factory()->create(['uuid' => $uuid, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

test('une référence de dépense est unique en base (numérotation fiscale)', function () {
    $user = App\Models\User::factory()->create();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    expect(fn () => Expense::factory()->create(['reference' => $expense->reference, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});
