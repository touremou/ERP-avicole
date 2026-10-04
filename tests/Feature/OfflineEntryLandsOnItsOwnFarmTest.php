<?php

use App\Models\Expense;
use App\Models\Farm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SAISIE TERRAIN ATTERRIT SUR LE SITE OÙ ELLE A ÉTÉ FAITE.
 *
 * L'application terrain pousse sa file sous l'en-tête X-Farm-Id. Elle le
 * lisait au moment du PUSH : une dépense saisie à Kindia, poussée après une
 * bascule vers Kérouané, entrait dans la comptabilité de Kérouané. Le client
 * marque désormais chaque saisie de son site (`OutboxEntry.farm_id`) et pousse
 * un lot par site, sous l'en-tête de ce site (`lotsParSite`, mobile/src/offline/sync.ts).
 *
 * Ce fichier garde la moitié serveur de ce contrat : c'est l'EN-TÊTE DU LOT, et
 * non le site par défaut du compte, qui décide où la saisie s'écrit.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->kindia = $this->farm;
    $this->kerouane = Farm::create(['code' => 'KER', 'name' => 'Kérouané']);

    foreach ([[$this->kindia->id, false], [$this->kerouane->id, true]] as [$farmId, $defaut]) {
        DB::table('farm_user')->insert([
            'farm_id' => $farmId, 'user_id' => $this->adminUser->id,
            'is_default' => $defaut, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

function depenseTerrain(string $uuid): array
{
    return ['operations' => [[
        'op_uuid' => $uuid,
        'type'    => 'expense.create',
        'payload' => [
            'uuid' => $uuid, 'category' => 'carburant', 'label' => 'Gasoil groupe',
            'amount' => 15000, 'expense_date' => now()->toDateString(),
        ],
    ]]];
}

test('poussée sous l’en-tête de son site, la saisie y atterrit — pas sur le site par défaut', function () {
    Sanctum::actingAs($this->adminUser);
    $uuid = (string) Str::uuid();

    $this->withHeader('X-Farm-Id', (string) $this->kindia->id)
        ->postJson('/api/v1/sync/push', depenseTerrain($uuid))
        ->assertOk()
        ->assertJsonPath('results.0.status', 'success');

    expect((int) Expense::withoutGlobalScopes()->where('uuid', $uuid)->value('farm_id'))
        ->toBe($this->kindia->id);
});

test('sans en-tête (saisie antérieure), elle suit le site par défaut — comportement inchangé', function () {
    Sanctum::actingAs($this->adminUser);
    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/sync/push', depenseTerrain($uuid))->assertOk();

    expect((int) Expense::withoutGlobalScopes()->where('uuid', $uuid)->value('farm_id'))
        ->toBe($this->kerouane->id);
});
