<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN LOT MODIFIÉ PAR LE TERRAIN RÉÉCRIVAIT SES EFFECTIFS.
 *
 * Au bureau, la modification d'un lot passe par `UpdateBatch`, dont la règle
 * fondamentale est écrite en tête : elle ne touche JAMAIS aux effectifs
 * (initial, vivant, morts). L'effectif vivant ne bouge que par les pointages,
 * les transferts ou une reconstruction.
 *
 * La synchro, elle, mettait à jour un lot existant par un `updateOrCreate` brut,
 * avec tous les champs du téléphone. Le téléphone n'émet `batch.upsert` qu'à la
 * mise en lot ; la mise à jour, c'est donc le REJEU de cette création — une
 * réponse perdue, une horloge en avance sur celle du serveur.
 *
 * Mesuré — 300 sujets livrés, 10 morts au transport, 5 000 GNF le sujet, puis
 * 5 morts pointés :
 *
 *   • l'effectif vivant REVENAIT à 290 : les 5 morts ressuscitaient, alors que
 *     le pointage qui les compte existe toujours ;
 *   • l'effectif initial passait de 290 à 300. La création suit la convention du
 *     bureau (vivants reçus, morts du transport à part) ; la mise à jour
 *     reprenait celle du téléphone (livrés). Le même lot avait deux effectifs
 *     initiaux selon la porte qui l'avait écrit en dernier ;
 *   • le coût d'acquisition passait de 1 450 000 à 1 500 000 GNF, pour la même
 *     raison.
 *
 * La mise à jour passe désormais par `UpdateBatch`, comme au bureau.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    Sanctum::actingAs($this->adminUser);
    Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_owner' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
    );
    $this->poulailler = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-CHAIR', 'type' => 'chair',
        'status' => Building::STATUS_VIDE, 'capacity' => 500,
    ]);

    $this->uuid = (string) Str::uuid();
    $this->miseEnLot = [
        'uuid' => $this->uuid, 'code' => 'TER-REJEU', 'type' => 'chair',
        'building_id' => $this->poulailler->id,
        'initial_quantity' => 300, 'current_quantity' => 290, 'qty_dead' => 10,
        'status' => 'Actif', 'arrival_date' => today()->subDays(3)->toDateString(),
        'buy_price_per_unit' => 5000, 'updated_at' => now()->toIso8601String(),
    ];
});

function envoyerMiseEnLot(Tests\TestCase $test, int $ferme, array $payload): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'batch.upsert',
        'payload' => $payload,
    ]]], ['X-Farm-Id' => (string) $ferme])->assertOk()->json('results.0');
}

test('le rejeu d’une mise en lot ne ressuscite pas les morts pointés', function () {
    expect(envoyerMiseEnLot($this, $this->farm->id, $this->miseEnLot)['status'])->toBe('success');

    $lot = Batch::withoutGlobalScopes()->where('uuid', $this->uuid)->firstOrFail();
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $lot->id,
        'check_date' => today()->toDateString(), 'mortality' => 5,
    ]);
    expect((int) $lot->fresh()->current_quantity)->toBe(285);

    // Rejeu, horloge du téléphone en avance : il gagne le Last-Write-Wins.
    $rejeu = envoyerMiseEnLot($this, $this->farm->id, array_merge($this->miseEnLot, [
        'updated_at' => now()->addMinutes(5)->toIso8601String(),
    ]));

    $lot = $lot->fresh();
    expect($rejeu['status'])->toBe('success')
        ->and((int) $lot->current_quantity)->toBe(285)
        ->and((int) $lot->initial_quantity)->toBe(290)
        ->and((int) $lot->qty_dead)->toBe(10)
        ->and((float) $lot->total_acquisition_cost)->toBe(1450000.0);
});

test('les métadonnées du lot restent modifiables par le terrain — non-régression', function () {
    envoyerMiseEnLot($this, $this->farm->id, $this->miseEnLot);

    $rejeu = envoyerMiseEnLot($this, $this->farm->id, array_merge($this->miseEnLot, [
        'buy_price_per_unit' => 6000,
        'birth_date'         => today()->subDays(5)->toDateString(),
        'updated_at'         => now()->addMinutes(5)->toIso8601String(),
    ]));

    $lot = Batch::withoutGlobalScopes()->where('uuid', $this->uuid)->firstOrFail();
    expect($rejeu['status'])->toBe('success')
        ->and((float) $lot->buy_price_per_unit)->toBe(6000.0)
        // Le coût suit le prix, sur l'effectif initial du BUREAU (vivants reçus).
        ->and((float) $lot->total_acquisition_cost)->toBe(290 * 6000.0)
        ->and($lot->birth_date->toDateString())->toBe(today()->subDays(5)->toDateString());
});

test('un rejeu sans statut ne rouvre pas un lot clôturé', function () {
    envoyerMiseEnLot($this, $this->farm->id, $this->miseEnLot);
    Batch::withoutGlobalScopes()->where('uuid', $this->uuid)->update(['status' => 'Terminé']);

    $payload = array_merge($this->miseEnLot, ['updated_at' => now()->addMinutes(5)->toIso8601String()]);
    unset($payload['status']);

    expect(envoyerMiseEnLot($this, $this->farm->id, $payload)['status'])->toBe('success')
        ->and(Batch::withoutGlobalScopes()->where('uuid', $this->uuid)->value('status'))->toBe('Terminé');
});
