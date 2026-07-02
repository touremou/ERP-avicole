<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Farm;
use App\Models\Reception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Audit 360 §2.1 / §4 — vague 3 : étanchéité multi-fermes côté WEB,
 * verrouillage du tri d'oeufs (is_graded), et anti-fraude expéditions
 * (expéditeur ≠ récepteur, réception unique, habilitation du récepteur).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    // Expédition « en route » avec 1 ligne, prête à réceptionner.
    $this->makeDispatch = function (int $senderId, ?int $receiverId = null): array {
        $dispatch = [
            'dispatch_number' => 'EXP-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'dispatched_by'   => $senderId,
            'driver_name'     => 'Chauffeur Test',
            'dispatch_date'   => now()->toDateString(),
            'destination'     => 'Dépôt Kindia',
            'status'          => 'expedie',
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
        if (Schema::hasColumn('dispatches', 'intended_receiver_id')) {
            $dispatch['intended_receiver_id'] = $receiverId;
        }
        if (Schema::hasColumn('dispatches', 'farm_id')) {
            $dispatch['farm_id'] = $this->farm->id;
        }
        $dispatchId = DB::table('dispatches')->insertGetId($dispatch);

        $item = [
            'dispatch_id'          => $dispatchId,
            'product_type'         => 'oeufs',
            'product_name'         => 'Oeufs calibre L',
            'quantity_dispatched'  => 10,
            'unit'                 => 'unite',
            'created_at'           => now(),
            'updated_at'           => now(),
        ];
        if (Schema::hasColumn('dispatch_items', 'farm_id')) {
            $item['farm_id'] = $this->farm->id; // sinon invisible au FarmScope
        }
        $itemId = DB::table('dispatch_items')->insertGetId($item);

        return [$dispatchId, $itemId];
    };

    $this->receptionPayload = fn (int $itemId, float $received = 10) => [
        'reception_date' => now()->toDateString(),
        'items'          => [[
            'dispatch_item_id'  => $itemId,
            'quantity_received' => $received,
        ]],
    ];
});

// ─── ÉTANCHÉITÉ MULTI-FERMES (WEB) ───

test('le web est borné à la ferme courante : les lots d\'une autre ferme sont invisibles', function () {
    $batchA = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 100,
    ]);

    // Ferme B avec son lot, créé sous SON contexte.
    $farmB = Farm::firstOrCreate(['code' => 'FB-001'], ['name' => 'Ferme B', 'is_active' => true]);
    session(['current_farm_id' => $farmB->id]);
    $batchB = Batch::factory()->create([
        'building_id'      => Building::factory()->create(['type' => 'chair'])->id,
        'status'           => 'Actif',
        'current_quantity' => 50,
    ]);

    // Retour au contexte ferme A pour la requête.
    session(['current_farm_id' => $this->farm->id]);

    $this->actingAs($this->managerUser)
        ->get(route('batches.index'))
        ->assertOk()
        ->assertSee($batchA->code)
        ->assertDontSee($batchB->code);
});

// ─── TRI D'OEUFS : is_graded VERROUILLE LA COLLECTE ───

test('modifier une collecte déjà triée est refusé (le tri fige le total)', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 200,
    ]);

    $egg = [
        'batch_id'             => $batch->id,
        'production_date'      => now()->toDateString(),
        'total_eggs_collected' => 150,
        'broken_eggs'          => 2,
        'small_eggs'           => 3,
        'is_graded'            => true, // journée déjà TRIÉE et mise en stock
        'created_at'           => now(),
        'updated_at'           => now(),
    ];
    if (Schema::hasColumn('egg_productions', 'laying_rate')) {
        $egg['laying_rate'] = 75;
    }
    if (Schema::hasColumn('egg_productions', 'farm_id')) {
        $egg['farm_id'] = $this->farm->id;
    }
    $eggId = DB::table('egg_productions')->insertGetId($egg);

    $this->actingAs($this->managerUser)
        ->put(route('egg-productions.update', $eggId), [
            'total_eggs_collected' => 999, // tentative de réécrire l'historique
        ])
        ->assertSessionHas('error');

    expect((int) DB::table('egg_productions')->where('id', $eggId)->value('total_eggs_collected'))
        ->toBe(150); // total figé
});

// ─── EXPÉDITIONS : ANTI-FRAUDE & RÉCEPTION UNIQUE ───

test('anti-fraude : l\'expéditeur ne peut pas réceptionner sa propre expédition', function () {
    // L'expéditeur est responsable logistique (M) : il passe le contrôle
    // d'accès — c'est la règle MÉTIER de l'Action qui doit le bloquer.
    [$dispatchId, $itemId] = ($this->makeDispatch)($this->managerUser->id);

    $this->actingAs($this->managerUser)
        ->post(route('dispatches.reception.store', $dispatchId), ($this->receptionPayload)($itemId))
        ->assertSessionHas('error');

    expect(Reception::count())->toBe(0);
    expect(DB::table('dispatches')->where('id', $dispatchId)->value('status'))->toBe('expedie');
});

test('un tiers ni désigné ni responsable logistique ne peut pas réceptionner', function () {
    [$dispatchId, $itemId] = ($this->makeDispatch)($this->managerUser->id, $this->adminUser->id);

    // operator : ni récepteur désigné, ni droit M.
    $this->actingAs($this->operatorUser)
        ->post(route('dispatches.reception.store', $dispatchId), ($this->receptionPayload)($itemId))
        ->assertSessionHas('error');

    expect(Reception::count())->toBe(0);
});

test('le récepteur DÉSIGNÉ (même sans droit M) valide la réception', function () {
    // Récepteur désigné = operator (L,C seulement) : l'habilitation par
    // désignation doit suffire (règle terrain : le magasinier réceptionne).
    [$dispatchId, $itemId] = ($this->makeDispatch)($this->managerUser->id, $this->operatorUser->id);

    $this->actingAs($this->operatorUser)
        ->post(route('dispatches.reception.store', $dispatchId), ($this->receptionPayload)($itemId))
        ->assertSessionHas('success');

    expect(Reception::count())->toBe(1);
    expect(DB::table('dispatches')->where('id', $dispatchId)->value('status'))->toBe('receptionne');
})->skip(fn () => ! Schema::hasColumn('dispatches', 'intended_receiver_id'), 'Pas de récepteur désigné dans ce schéma');

test('une expédition déjà réceptionnée ne peut pas l\'être une seconde fois', function () {
    [$dispatchId, $itemId] = ($this->makeDispatch)($this->managerUser->id, $this->adminUser->id);

    $this->actingAs($this->adminUser)
        ->post(route('dispatches.reception.store', $dispatchId), ($this->receptionPayload)($itemId))
        ->assertSessionHas('success');

    expect(Reception::count())->toBe(1);

    // Rejeu (double-clic / autre utilisateur habilité) → refus, pas de doublon.
    $this->actingAs($this->adminUser)
        ->post(route('dispatches.reception.store', $dispatchId), ($this->receptionPayload)($itemId))
        ->assertSessionHas('error');

    expect(Reception::count())->toBe(1);
});
