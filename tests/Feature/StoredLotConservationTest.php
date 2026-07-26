<?php

use App\Actions\Stock\RecordStoredLotCheck;
use App\Models\Employee;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StoredLot;
use App\Models\StoredLotCheck;
use App\Models\TaskAssignment;
use App\Services\TaskSchedulerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * T2 — Sécuriser le stock de spéculation.
 *
 * T1 a rendu la comptabilité honnête. T2 s'attaque aux deux façons de perdre
 * l'argent qu'on espérait gagner en attendant :
 *
 *  - LA MATIÈRE SE DÉGRADE : sans pesée périodique, la perte se découvre le jour
 *    de la vente ;
 *  - « PLUS TARD » DÉRIVE : sans prix-cible ni date butoir, le stock se garde
 *    par inertie jusqu'au rebut.
 *
 * Les propriétés verrouillées ici : la freinte se DÉDUIT d'une pesée et passe
 * par un ajustement de stock formel (sinon lot et inventaire divergent), un
 * constat grave EXIGE une décision, et un contrôle échu devient une tâche.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

/** Lot de gombo séché : 100 kg à 12 000/kg, objectif 25 000, butoir dans 60 j. */
function driedOkraLot(array $overrides = []): StoredLot
{
    $stock = Stock::create([
        'item_name' => 'Gombo séché ' . Str::random(3),
        'category' => Stock::CAT_PRODUITS_FINIS,
        'unit' => 'kg', 'current_quantity' => 100, 'alert_threshold' => 0,
        'unit_price' => 12000, 'last_unit_price' => 12000,
    ]);

    return StoredLot::create(array_merge([
        'stock_id'            => $stock->id,
        'label'              => 'Gombo séché — récolte du 12/08',
        'quantity_initial'   => 100,
        'quantity_current'   => 100,
        'unit'               => 'kg',
        'unit_cost'          => 12000,
        'target_unit_price'  => 25000,
        'opened_at'          => now()->subDays(20)->toDateString(),
        'hold_until'         => now()->addDays(60)->toDateString(),
        'check_interval_days' => 14,
        'status'             => StoredLot::STATUS_EN_STOCK,
    ], $overrides));
}

function check(StoredLot $lot, array $data): StoredLotCheck
{
    return app(RecordStoredLotCheck::class)->execute($lot, $data, auth()->id());
}

// ───────────────── FREINTE : dérivée d'une pesée, répercutée à l'inventaire ─────────────────

test('la freinte se déduit de la pesée, elle ne se saisit pas', function () {
    $lot = driedOkraLot();

    $result = check($lot, ['weighed_quantity' => 94.5, 'condition' => 'bon']);

    expect((float) $result->weighed_quantity)->toBe(94.5);
    expect((float) $result->shrinkage_quantity)->toBe(5.5);
    expect((float) $lot->fresh()->quantity_current)->toBe(94.5);
    expect($lot->fresh()->shrinkage_percent)->toBe(5.5);
});

test('la freinte descend sur l’INVENTAIRE par un ajustement formel', function () {
    $lot = driedOkraLot();

    check($lot, ['weighed_quantity' => 90, 'condition' => 'humide', 'action_taken' => 'sechage']);

    // Corriger le lot sans toucher l'inventaire ferait diverger les deux : le lot
    // dirait 90 kg et le magasin 100.
    expect((float) $lot->stock->fresh()->current_quantity)->toBe(90.0);

    $adjustment = StockAdjustment::where('stock_id', $lot->stock_id)->latest('id')->first();
    expect($adjustment)->not->toBeNull();
    expect($adjustment->reason)->toBe('freinte');
    expect((float) $adjustment->delta)->toBe(-10.0);
    // Valorisée au coût : la freinte est une perte chiffrée, pas un simple écart.
    expect((float) $adjustment->unit_cost)->toBe(12000.0);
});

test('un contrôle sans perte ne crée AUCUN ajustement de stock', function () {
    $lot = driedOkraLot();

    check($lot, ['weighed_quantity' => 100, 'condition' => 'bon']);

    // Un ajustement à zéro polluerait le registre de démarque.
    expect(StockAdjustment::where('stock_id', $lot->stock_id)->count())->toBe(0);
    expect((float) $lot->fresh()->quantity_current)->toBe(100.0);
});

test('une pesée SUPÉRIEURE au lot est refusée (un lot ne grossit pas)', function () {
    $lot = driedOkraLot();

    expect(fn () => check($lot, ['weighed_quantity' => 105, 'condition' => 'bon']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(StoredLotCheck::count())->toBe(0);
    expect((float) $lot->fresh()->quantity_current)->toBe(100.0);
});

test('un contrôle sans pesée est accepté et ne change pas la quantité', function () {
    $lot = driedOkraLot();

    // Passer voir sans balance reste utile : on relève l'état et le cours.
    $result = check($lot, ['condition' => 'bon', 'market_price' => 22000]);

    expect($result->weighed_quantity)->toBeNull();
    expect((float) $result->shrinkage_quantity)->toBe(0.0);
    expect((float) $lot->fresh()->quantity_current)->toBe(100.0);
    expect((float) $lot->fresh()->last_market_price)->toBe(22000.0);
});

// ───────────────── UN CONSTAT GRAVE EXIGE UNE DÉCISION ─────────────────

test('insectes constatés SANS décision est refusé', function () {
    $lot = driedOkraLot();

    // C'est le contrôle-alibi : celui qu'on coche pour être en règle.
    expect(fn () => check($lot, ['weighed_quantity' => 98, 'condition' => 'insectes', 'action_taken' => 'aucune']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(StoredLotCheck::count())->toBe(0);
});

test('insectes AVEC traitement décidé est accepté', function () {
    $lot = driedOkraLot();

    $result = check($lot, ['weighed_quantity' => 98, 'condition' => 'insectes', 'action_taken' => 'traitement']);

    expect($result->condition)->toBe('insectes');
    expect($result->action_taken)->toBe('traitement');
    expect($result->needs_action)->toBeTrue();
});

test('une reprise d’humidité seule n’exige pas de décision', function () {
    $lot = driedOkraLot();

    // Constat de vigilance, pas de sinistre : on n'impose pas une décision.
    $result = check($lot, ['weighed_quantity' => 99, 'condition' => 'humide']);

    expect($result->action_taken)->toBe('aucune');
});

test('la destruction solde le lot et vide sa quantité', function () {
    $lot = driedOkraLot();

    check($lot, ['weighed_quantity' => 60, 'condition' => 'moisissure', 'action_taken' => 'destruction']);

    $lot = $lot->fresh();
    expect((float) $lot->quantity_current)->toBe(0.0);
    expect($lot->status)->toBe(StoredLot::STATUS_PERTE);
    expect($lot->closed_at)->not->toBeNull();
    // L'inventaire perd les 100 kg, pas seulement les 40 de freinte.
    expect((float) $lot->stock->fresh()->current_quantity)->toBe(0.0);
});

// ───────────────── PRIX-CIBLE ET ÉCHÉANCE ─────────────────

test('le prix-cible devient exploitable dès qu’un cours est relevé', function () {
    $lot = driedOkraLot();

    // Sans relevé, on ne conclut pas : ni atteint, ni non atteint.
    expect($lot->target_reached)->toBeNull();

    check($lot, ['condition' => 'bon', 'market_price' => 20000]);
    expect($lot->fresh()->target_reached)->toBeFalse();

    check($lot, ['condition' => 'bon', 'market_price' => 26000]);
    expect($lot->fresh()->target_reached)->toBeTrue();

    $codes = array_column($lot->fresh()->alerts(), 'code');
    expect($codes)->toContain('target_reached');
});

test('un contrôle sans relevé de cours n’EFFACE pas le dernier connu', function () {
    $lot = driedOkraLot();

    check($lot, ['condition' => 'bon', 'market_price' => 24000]);
    // Contrôle un jour de marché fermé : pas de cours à relever.
    check($lot, ['condition' => 'bon']);

    expect((float) $lot->fresh()->last_market_price)->toBe(24000.0);
});

test('la marge au cours constaté déduit la freinte', function () {
    $lot = driedOkraLot();

    // 100 kg engagés à 12 000 = 1 200 000. Il reste 90 kg, cours à 20 000.
    check($lot, ['weighed_quantity' => 90, 'condition' => 'bon', 'market_price' => 20000]);

    // 90 × 20 000 − 1 200 000 = 600 000. Un cours qui monte ne compense pas
    // forcément la perte de poids : c'est le seul chiffre qui tranche.
    expect($lot->fresh()->margin_at_market)->toBe(600_000.0);
});

test('l’échéance dépassée et l’absence d’échéance sont signalées', function () {
    $late = driedOkraLot(['hold_until' => now()->subDays(5)->toDateString()]);
    expect(array_column($late->alerts(), 'code'))->toContain('deadline_passed');
    expect($late->is_past_deadline)->toBeTrue();

    $drifting = driedOkraLot(['hold_until' => null]);
    // L'absence d'échéance EST la dérive : on la signale comme telle.
    expect(array_column($drifting->alerts(), 'code'))->toContain('no_deadline');

    $soon = driedOkraLot(['hold_until' => now()->addDays(7)->toDateString()]);
    expect(array_column($soon->alerts(), 'code'))->toContain('deadline_near');
});

test('une freinte lourde est signalée comme destructrice de marge', function () {
    $lot = driedOkraLot();

    check($lot, ['weighed_quantity' => 85, 'condition' => 'humide']);

    expect(array_column($lot->fresh()->alerts(), 'code'))->toContain('heavy_shrinkage');
});

test('un lot clos ne produit plus aucune alerte', function () {
    $lot = driedOkraLot(['hold_until' => now()->subDays(30)->toDateString()]);
    $lot->update(['status' => StoredLot::STATUS_VENDU, 'closed_at' => now()->toDateString()]);

    expect($lot->fresh()->alerts())->toBe([]);
});

// ───────────────── LE CONTRÔLE DEVIENT UNE TÂCHE ─────────────────

test('un contrôle échu génère une tâche avec pesée exigée', function () {
    Employee::factory()->create(['status' => 'Actif']);
    // Ouvert il y a 20 j, intervalle 14 j, jamais contrôlé → échu.
    $lot = driedOkraLot();

    app(TaskSchedulerService::class)->generateForDate(now(), session('current_farm_id'));

    $task = TaskAssignment::where('stored_lot_id', $lot->id)->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toContain('Contrôle de conservation');
    // La pesée EST l'objet du contrôle : « je suis passé voir » ne se recoupe
    // avec rien, « 86,5 kg » se compare au relevé précédent.
    expect($task->proof_type)->toBe('valeur');
    expect($task->proof_unit)->toBe('kg');
    expect($task->description)->toContain('Relever le cours du marché');
    expect($task->description)->toContain('Objectif de vente');
});

test('le générateur ne réempile pas une tâche par jour de retard', function () {
    Employee::factory()->create(['status' => 'Actif']);
    driedOkraLot();

    $scheduler = app(TaskSchedulerService::class);
    $farmId = session('current_farm_id');
    $scheduler->generateForDate(now(), $farmId);
    $scheduler->generateForDate(now()->addDay(), $farmId);
    $scheduler->generateForDate(now()->addDays(3), $farmId);

    expect(TaskAssignment::whereNotNull('stored_lot_id')->count())->toBe(1);
});

test('un lot contrôlé récemment ne génère pas de tâche', function () {
    Employee::factory()->create(['status' => 'Actif']);
    $lot = driedOkraLot();

    check($lot, ['condition' => 'bon', 'checked_at' => now()->subDay()]);

    app(TaskSchedulerService::class)->generateForDate(now(), session('current_farm_id'));

    // Prochaine échéance dans 13 jours : rien à demander aujourd'hui.
    expect(TaskAssignment::whereNotNull('stored_lot_id')->count())->toBe(0);
});

test('un lot clos ne génère plus de tâche de contrôle', function () {
    Employee::factory()->create(['status' => 'Actif']);
    $lot = driedOkraLot();
    $lot->update(['status' => StoredLot::STATUS_VENDU]);

    app(TaskSchedulerService::class)->generateForDate(now(), session('current_farm_id'));

    expect(TaskAssignment::whereNotNull('stored_lot_id')->count())->toBe(0);
});

// ───────────────── WEB ─────────────────

test('la page de conservation liste les lots et leurs alertes', function () {
    driedOkraLot(['hold_until' => now()->subDays(2)->toDateString()]);

    $this->get(route('stored-lots.index'))
        ->assertOk()
        ->assertSee('Gombo séché')
        ->assertSee('Échéance de détention dépassée', escape: false);
});

test('la page signale un stock conservable SANS suivi', function () {
    // Marchandise qui dort : ni objectif, ni échéance, ni contrôle.
    Stock::create([
        'item_name' => 'Mangue séchée', 'category' => Stock::CAT_PRODUITS_FINIS,
        'unit' => 'kg', 'current_quantity' => 40, 'alert_threshold' => 0, 'last_unit_price' => 9000,
    ]);

    $this->get(route('stored-lots.index'))
        ->assertOk()
        ->assertSee('Stock conservable sans suivi')
        ->assertSee('Mangue séchée');
});

test('ouvrir un lot au-delà du stock disponible est refusé', function () {
    $stock = Stock::create([
        'item_name' => 'Gombo séché', 'category' => Stock::CAT_PRODUITS_FINIS,
        'unit' => 'kg', 'current_quantity' => 30, 'alert_threshold' => 0, 'last_unit_price' => 12000,
    ]);

    $this->post(route('stored-lots.store'), [
        'stock_id' => $stock->id, 'label' => 'Trop grand lot',
        'quantity_initial' => 50, 'unit' => 'kg',
    ])->assertRedirect();

    // Un pari sur une marchandise inexistante.
    expect(StoredLot::count())->toBe(0);
});

test('la clôture ne touche PAS l’inventaire', function () {
    $lot = driedOkraLot();
    $before = (float) $lot->stock->current_quantity;

    $this->post(route('stored-lots.close', $lot), ['status' => 'vendu', 'reason' => 'Vendu au grossiste'])
        ->assertRedirect(route('stored-lots.index'));

    $lot = $lot->fresh();
    expect($lot->status)->toBe(StoredLot::STATUS_VENDU);
    // La vente décrémente le stock par son propre chemin : doubler la sortie ici
    // ferait disparaître la marchandise deux fois.
    expect((float) $lot->stock->fresh()->current_quantity)->toBe($before);
});

// ───────────────── MOBILE ─────────────────

test('mobile : stored_lot.check enregistre la freinte et le cours', function () {
    $lot = driedOkraLot();

    $payload = [
        'uuid' => (string) Str::uuid(),
        'stored_lot_id' => $lot->id,
        'checked_at' => now()->subHours(3)->toIso8601String(),
        'weighed_quantity' => 92.5,
        'condition' => 'humide',
        'action_taken' => 'sechage',
        'market_price' => 23000,
    ];

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'stored_lot.check', 'payload' => $payload,
    ]]]);

    $res->assertOk();
    expect($res->json('results.0.status'))->toBe('success');

    $lot = $lot->fresh();
    expect((float) $lot->quantity_current)->toBe(92.5);
    expect((float) $lot->last_market_price)->toBe(23000.0);
    expect((float) $lot->stock->fresh()->current_quantity)->toBe(92.5);

    // Rejeu → already_synced, pas un second contrôle ni une double freinte.
    $res2 = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'stored_lot.check', 'payload' => $payload,
    ]]]);
    expect($res2->json('results.0.status'))->toBe('already_synced');
    expect(StoredLotCheck::count())->toBe(1);
    expect((float) $lot->fresh()->quantity_current)->toBe(92.5);
});

test('mobile : un constat grave sans décision est refusé sans rejeu', function () {
    $lot = driedOkraLot();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'stored_lot.check',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'stored_lot_id' => $lot->id,
            'condition' => 'moisissure', 'action_taken' => 'aucune',
        ],
    ]]]);

    // ValidationException métier → conflict (bac « À corriger »), pas une erreur
    // rejouée indéfiniment.
    expect($res->json('results.0.status'))->toBe('conflict');
    expect(StoredLotCheck::count())->toBe(0);
});

test('mobile : contrôler un lot CLOS est refusé', function () {
    $lot = driedOkraLot();
    $lot->update(['status' => StoredLot::STATUS_VENDU]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'stored_lot.check',
        'payload' => ['uuid' => (string) Str::uuid(), 'stored_lot_id' => $lot->id, 'condition' => 'bon'],
    ]]]);

    // Le terrain travaillait sur une liste périmée : refus définitif.
    expect($res->json('results.0.status'))->toBe('conflict');
});

test('pull : seuls les lots OUVERTS descendent, et la liste est envoyée en entier', function () {
    driedOkraLot();
    $sold = driedOkraLot();
    $sold->update(['status' => StoredLot::STATUS_VENDU]);

    $lots = $this->getJson('/api/v1/sync/pull')->json('entities.stored_lots.upserts');
    expect($lots)->toHaveCount(1);
    expect($lots[0]['target_unit_price'])->not->toBeNull();

    // Envoi INTÉGRAL même en delta : un lot vendu quitte le périmètre sans
    // tombstone, un delta le laisserait dans la liste de contrôle du terrain.
    $delta = $this->getJson('/api/v1/sync/pull?since=' . urlencode(now()->addMinute()->toIso8601String()));
    $delta->assertOk();
    expect($delta->json('entities.stored_lots.upserts'))->toHaveCount(1);
});
