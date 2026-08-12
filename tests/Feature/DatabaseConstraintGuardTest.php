<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Expense;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    /*
     * DÉRIVÉ DU SCHÉMA, ET NON D'UNE LISTE ÉCRITE À LA MAIN.
     *
     * Ce test énumérait six tables, nommées une par une. Il ne pouvait donc
     * vérifier que ce dont quelqu'un s'était souvenu — et `water_readings`, ajoutée
     * plus tard avec un uuid de synchro, n'y figurait pas. Elle était la SEULE table
     * de la base sans index unique sur son uuid, et rien ne le signalait.
     *
     * Conséquence concrète : un ravitaillement de citerne rejoué (mauvais réseau,
     * double appui, deux appareils) pouvait s'enregistrer deux fois, et
     * `waterReadingCreate` ajoute le volume au niveau de la citerne APRÈS insertion.
     * Le doublon gonflait donc la citerne d'autant, et comptait le coût deux fois.
     *
     * SyncService écrit pourtant la règle en tête de fichier : « IDEMPOTENCE par
     * uuid — doublée d'index UNIQUE en base ». Le contrôle applicatif
     * `where('uuid')->exists()` suffit en série ; deux rejeux strictement
     * concurrents le passent tous les deux. L'index est la vraie garantie.
     *
     * Un garde-fou qui repose sur une liste tenue à jour à la main reproduit
     * exactement le défaut qu'il surveille. Celui-ci interroge le schéma : toute
     * table portant une colonne `uuid` doit porter l'index qui va avec.
     *
     * Introspection par le schéma et non par PRAGMA : la garantie doit valoir sur le
     * moteur de PRODUCTION autant que sur celui des tests, et PRAGMA n'existe que
     * sur sqlite.
     */
    $missing = [];
    $checked = 0;

    foreach (Schema::getTableListing() as $table) {
        $short = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        if (! in_array('uuid', Schema::getColumnListing($short), true)) {
            continue;
        }

        $checked++;

        $unique = collect(Schema::getIndexes($short))
            ->contains(fn ($i) => ($i['unique'] ?? false) && in_array('uuid', $i['columns'], true));

        if (! $unique) {
            $missing[] = $short;
        }
    }

    // Garde-fou du garde-fou : sans table à vérifier, il passerait sans rien faire.
    expect($checked)->toBeGreaterThan(10);

    expect($missing)->toBe([], "Table(s) à uuid de synchro SANS index unique — deux rejeux concurrents y créeraient un doublon :\n  "
        . implode("\n  ", $missing));
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

test('un ravitaillement de citerne ne peut pas être enregistré deux fois', function () {
    /*
     * LE COMPORTEMENT, pas seulement la structure. Un index déclaré mais posé sur la
     * mauvaise colonne passerait le test d'introspection ; celui-ci l'attrape.
     *
     * L'enjeu est concret : `waterReadingCreate` ajoute le volume au niveau de la
     * citerne APRÈS insertion. Un rejeu réseau qui passe — deux appels strictement
     * concurrents franchissent tous deux le `where('uuid')->exists()` — gonflerait
     * donc la citerne d'un ravitaillement fantôme, et compterait son coût deux fois.
     */
    $source = \App\Models\WaterSource::create([
        'name' => 'Citerne test', 'type' => 'citerne',
        'capacity_liters' => 10000, 'current_level_liters' => 2000,
    ]);

    $uuid = (string) Str::uuid();

    $ligne = [
        'user_id' => \App\Models\User::factory()->create()->id,
        'water_source_id' => $source->id,
        'reading_date' => '2026-08-01',
        'volume_consumed_liters' => 0,
        'volume_added_liters' => 3000,
        'is_refill' => true,
        'uuid' => $uuid,
    ];

    \App\Models\WaterReading::create($ligne);

    expect(fn () => \App\Models\WaterReading::create($ligne))->toThrow(QueryException::class);
});
