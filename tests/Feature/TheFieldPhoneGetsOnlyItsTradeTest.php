<?php

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Role;
use App\Models\Stock;
use App\Models\StoredLot;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\NotificationHub;
use App\Support\ProfilsMetier;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TÉLÉPHONE NE REÇOIT QUE LE MÉTIER DE SON PROFIL.
 *
 * Audit des droits côté application terrain, après celui du bureau : chaque
 * profil métier a été connecté à l'API, et tout ce qu'elle renvoie relevé.
 *   • la fiche d'un lot envoyait marge, CA et coûts à tout lecteur de l'élevage ;
 *   • les lots stockés descendaient leur coût de revient et leurs prix à tout
 *     lecteur du stock ;
 *   • un droit retiré laissait hors ligne, dans le téléphone, ce qui avait été
 *     téléchargé avant ;
 *   • les alertes allaient à tout abonné — et l'abonnement est coché par
 *     défaut : un agent recevait le CA de la veille, un dépassement de budget.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();
});

function telephoneDuProfil(string $profil): User
{
    $compte = User::factory()->create(['role_id' => Role::where('name', $profil)->value('id')]);
    SitesDuCompte::rattacher($compte, [(int) Farm::where('code', 'FT-001')->value('id')]);

    return $compte;
}

function synchroDe(Tests\TestCase $test, User $compte): array
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($compte);

    return $test->getJson('/api/v1/sync/pull')->assertOk()->json('entities');
}

test('la fiche d’un lot ne porte ses montants qu’à qui voit l’argent', function () {
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Actif', 'buy_price_per_unit' => 4939, 'margin' => 1250000]);

    Sanctum::actingAs(telephoneDuProfil('profil-technicien-elevage'));
    $fiche = $this->getJson("/api/v1/batches/{$lot->id}")->assertOk()->json('data');
    expect($fiche)->toHaveKey('code')->not->toHaveKey('margin')->not->toHaveKey('buy_price_per_unit');

    app('auth')->forgetGuards();
    Sanctum::actingAs(telephoneDuProfil('profil-gerant'));
    expect($this->getJson("/api/v1/batches/{$lot->id}")->json('data'))->toHaveKey('margin');
});

test('les lots stockés descendent sans coût ni prix chez qui ne voit pas l’argent', function () {
    $stock = Stock::create(['item_name' => 'Maïs', 'category' => Stock::CAT_PRODUITS_FINIS, 'unit' => 'kg',
        'current_quantity' => 100, 'alert_threshold' => 0]);
    StoredLot::create(['stock_id' => $stock->id, 'label' => 'Maïs — récolte d’août', 'quantity_initial' => 100,
        'quantity_current' => 100, 'unit' => 'kg', 'unit_cost' => 3000, 'target_unit_price' => 5000,
        'opened_at' => today()->toDateString(), 'check_interval_days' => 14, 'status' => StoredLot::STATUS_EN_STOCK]);

    $lotTechnicien = synchroDe($this, telephoneDuProfil('profil-technicien-elevage'))['stored_lots']['upserts'][0];
    expect($lotTechnicien)->toHaveKey('label')->not->toHaveKey('unit_cost')->not->toHaveKey('target_unit_price');

    $lotGerant = synchroDe($this, telephoneDuProfil('profil-gerant'))['stored_lots']['upserts'][0];
    expect($lotGerant)->toHaveKey('unit_cost');
});

test('un référentiel interdit est signalé, pour que le téléphone purge son miroir', function () {
    $technicien = synchroDe($this, telephoneDuProfil('profil-technicien-elevage'));
    expect($technicien['clients']['forbidden'] ?? false)->toBeTrue()
        ->and($technicien['batches'])->not->toHaveKey('forbidden');

    $vendeur = synchroDe($this, telephoneDuProfil('profil-vendeur'));
    expect($vendeur['clients'])->not->toHaveKey('forbidden');
});

test('une alerte ne va qu’à qui peut lire ce qu’elle dit', function () {
    Notification::fake();
    $agent = telephoneDuProfil('profil-agent-terrain');
    $gerant = telephoneDuProfil('profil-gerant');

    // Argent : le dépassement de budget va au gérant, pas à l'agent.
    app(NotificationHub::class)->alertBudgetOverrun('aliment', (int) now()->year, (int) now()->month, 1_500_000, 1_000_000);
    Notification::assertSentTo($gerant, AlertNotification::class);
    Notification::assertNotSentTo($agent, AlertNotification::class);

    // Élevage : la mortalité va à l'agent, qui lit l'élevage.
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif']);
    app(NotificationHub::class)->alertMortality($lot, 50, 5.0);
    Notification::assertSentTo($agent, AlertNotification::class);
});
