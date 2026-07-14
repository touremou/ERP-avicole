<?php

use App\Models\Client;
use App\Models\Farm;
use App\Models\FinishedProduct;
use App\Models\Module;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SlaughterOrder;
use App\Models\SlaughterReception;
use App\Models\User;
use App\Services\SlaughterService;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Abattage à façon (E8) — trois modèles de facturation de prestation :
 *   par_sujet | par_kg_vif | par_kg_carcasse, + minimum forfaitaire.
 *
 * Invariants :
 *  - le tarif est FIGÉ sur l'ordre (un changement de réglage ultérieur ne
 *    réécrit pas un devis accepté) ;
 *  - à l'exécution : prestation calculée + FACTURE BROUILLON générée dans
 *    le module Commerce, rattachée à l'ordre ;
 *  - RG-07 : les produits d'un ordre à façon n'entrent JAMAIS au stock
 *    vendable (abattage ET découpe) ;
 *  - façon exige client + réception ante-mortem (CCP 1 pour tous).
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FA-001'], ['name' => 'Ferme A', 'is_active' => true]);

    $role = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M']]
    );
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false,
             'created_at' => $now, 'updated_at' => $now]
        );
    }

    $this->manager = User::factory()->create(['role_id' => $role->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->manager->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    session(['current_farm_id' => $this->farm->id]);
    $this->actingAs($this->manager);

    $this->client = Client::create(['client_id' => 'CL-FACON-01', 'name' => 'Restaurant Le Fouta', 'type' => 'professionnel']);
    $this->reception = SlaughterReception::create([
        'provider_id' => Provider::factory()->create()->id,
        'reception_date' => now()->toDateString(),
        'received_quantity' => 100, 'total_live_weight_kg' => 180,
        'sanitary_state' => 'conforme', 'fasting_respected' => 'oui',
        'decision' => 'accepte', 'controller_id' => $this->manager->id, 'validated_at' => now(),
    ]);
});

function makeFaconOrder(SlaughterReception $reception, Client $client, User $requester, string $model, float $rate): SlaughterOrder
{
    return SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(),
        'reception_id' => $reception->id,
        'client_id'    => $client->id,
        'planned_date' => now()->toDateString(),
        'planned_quantity' => 100,
        'status'       => 'planifie',
        'requested_by' => $requester->id,
        'service_type' => 'facon',
        'billing_model' => $model,
        'billing_rate' => $rate,
    ]);
}

function executeFacon(SlaughterOrder $order): void
{
    app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity'         => 100,
        'total_live_weight_kg'    => 180,
        'total_carcass_weight_kg' => 130,
        'execution_date'          => now()->toDateString(),
    ]);
}

test('modèle PAR SUJET : 100 sujets × 2500 GNF = 250 000, facture brouillon générée', function () {
    $order = makeFaconOrder($this->reception, $this->client, $this->manager, 'par_sujet', 2500);
    executeFacon($order);

    $fresh = $order->fresh();
    expect((float) $fresh->service_fee)->toBe(250_000.0);

    $sale = Sale::find($fresh->service_sale_id);
    expect($sale)->not->toBeNull()
        ->and($sale->status)->toBe('brouillon')
        ->and($sale->type)->toBe('facture')
        ->and($sale->client_id)->toBe($this->client->id)
        ->and((float) $sale->items()->sum(DB::raw('quantity * unit_price')))->toBe(250_000.0);
});

test('modèle AU KG VIF : 180 kg × 1200 GNF = 216 000', function () {
    $order = makeFaconOrder($this->reception, $this->client, $this->manager, 'par_kg_vif', 1200);
    executeFacon($order);

    expect((float) $order->fresh()->service_fee)->toBe(216_000.0);
});

test('modèle AU KG CARCASSE : 130 kg × 1800 GNF = 234 000', function () {
    $order = makeFaconOrder($this->reception, $this->client, $this->manager, 'par_kg_carcasse', 1800);
    executeFacon($order);

    expect((float) $order->fresh()->service_fee)->toBe(234_000.0);
});

test('minimum forfaitaire : un petit lot est facturé au plancher, ligne « forfait » lisible', function () {
    // 5 sujets × 2500 = 12 500 < minimum 25 000 → plancher appliqué.
    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(),
        'reception_id' => $this->reception->id, 'client_id' => $this->client->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 5,
        'status' => 'planifie', 'requested_by' => $this->manager->id,
        'service_type' => 'facon', 'billing_model' => 'par_sujet', 'billing_rate' => 2500,
    ]);

    app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity' => 5, 'total_live_weight_kg' => 9,
        'total_carcass_weight_kg' => 6.5, 'execution_date' => now()->toDateString(),
    ]);

    $fresh = $order->fresh();
    expect((float) $fresh->service_fee)->toBe(25_000.0);

    $item = Sale::find($fresh->service_sale_id)->items()->first();
    expect($item->product_name)->toContain('minimum forfaitaire')
        ->and($item->unit)->toBe('forfait')
        ->and((float) $item->unit_price)->toBe(25_000.0);
});

test('RG-07 : un abattage à façon ne met RIEN au stock vendable (abattage ni découpe)', function () {
    $order = makeFaconOrder($this->reception, $this->client, $this->manager, 'par_sujet', 2500);
    executeFacon($order);

    // Aucune carcasse au stock produits finis.
    expect((float) FinishedProduct::sum('current_quantity_kg'))->toBe(0.0);

    // Découpe façon : les morceaux sont tracés mais le stock reste vide.
    app(SlaughterService::class)->executeCutting($order->fresh(), [
        'total_input_kg' => 130,
        'session_date'   => now()->toDateString(),
        'products'       => [
            ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 60],
            ['type' => 'filet', 'name' => 'Filets', 'kg' => 50],
        ],
    ]);

    expect((float) FinishedProduct::sum('current_quantity_kg'))->toBe(0.0)
        ->and($order->fresh()->cuttingSessions()->first()->products()->count())->toBe(2);
});

test('le tarif est figé sur l\'ordre : changer le réglage après coup ne change pas la prestation', function () {
    $order = makeFaconOrder($this->reception, $this->client, $this->manager, 'par_sujet', 2500);

    // Le tarif du réglage double APRÈS la création de l'ordre.
    \App\Models\Setting::set('abattoir.facon_rate_per_bird', 5000);

    executeFacon($order);
    expect((float) $order->fresh()->service_fee)->toBe(250_000.0); // 100 × 2500 figé
});

test('un ordre à façon sans client ou sans réception est refusé au web', function () {
    $this->post(route('slaughter.orders.store'), [
        'reception_id' => $this->reception->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 50,
        'service_type' => 'facon', 'billing_model' => 'par_sujet',
    ])->assertSessionHasErrors('client_id');

    $this->post(route('slaughter.orders.store'), [
        'batch_id' => null, 'client_id' => $this->client->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 50,
        'service_type' => 'facon', 'billing_model' => 'par_sujet',
    ])->assertSessionHasErrors();
});
