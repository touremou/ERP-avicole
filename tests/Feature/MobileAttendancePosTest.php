<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\Product;
use App\Models\SalePriceList;
use App\Models\SalePriceListItem;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * M6 — Présence au rassemblement du matin, et parité POS au terrain.
 *
 * Deux exigences testées ici :
 *  - le pointage mobile passe par la MÊME Action que la grille web, il est
 *    idempotent par (employé, jour) et borné à la ferme ;
 *  - le terrain reçoit de quoi vendre au bon prix hors réseau : tarif du
 *    client, code PLU, stock vendable — sans recevoir de données RH sensibles.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

function pushAttendance(array $rows, ?string $date = null, ?string $uuid = null): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'attendance.create',
        'payload' => [
            'uuid'            => $uuid ?? (string) Str::uuid(),
            'attendance_date' => $date ?? now()->toDateString(),
            'rows'            => $rows,
        ],
    ]]]);
}

// ─────────────────────────── PRÉSENCE ───────────────────────────

test('présence mobile : la grille du jour crée une ligne par employé', function () {
    $a = Employee::factory()->create(['first_name' => 'Awa', 'status' => 'Actif']);
    $b = Employee::factory()->create(['first_name' => 'Bakary', 'status' => 'Actif']);
    $c = Employee::factory()->create(['first_name' => 'Cissé', 'status' => 'Actif']);

    $res = pushAttendance([
        ['employee_id' => $a->id, 'status' => 'present'],
        ['employee_id' => $b->id, 'status' => 'retard'],
        ['employee_id' => $c->id, 'status' => 'absent'],
    ]);

    $res->assertOk();
    expect($res->json('results.0.status'))->toBe('success');
    expect($res->json('results.0.saved'))->toBe(3);

    expect(EmployeeAttendance::count())->toBe(3);
    expect(EmployeeAttendance::where('employee_id', $b->id)->value('status'))->toBe('retard');
    // Traçabilité : qui a pointé.
    expect(EmployeeAttendance::where('employee_id', $a->id)->value('recorded_by'))
        ->toBe($this->managerUser->id);
});

test('présence mobile : rejeu idempotent par (employé, jour), pas de doublon', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $rows = [['employee_id' => $employee->id, 'status' => 'present']];

    pushAttendance($rows)->assertOk();
    pushAttendance($rows)->assertOk();

    expect(EmployeeAttendance::where('employee_id', $employee->id)->count())->toBe(1);
});

test('présence mobile : une correction du soir écrase le statut du matin', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    pushAttendance([['employee_id' => $employee->id, 'status' => 'present']])->assertOk();
    pushAttendance([['employee_id' => $employee->id, 'status' => 'absent']])->assertOk();

    expect(EmployeeAttendance::where('employee_id', $employee->id)->count())->toBe(1);
    expect(EmployeeAttendance::where('employee_id', $employee->id)->value('status'))->toBe('absent');
});

test('présence mobile : un statut hors nomenclature est refusé sans rejeu', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    $res = pushAttendance([['employee_id' => $employee->id, 'status' => 'ferie']]);

    $res->assertOk();
    expect($res->json('results.0.status'))->toBe('validation_failed');
    expect(EmployeeAttendance::count())->toBe(0);
});

test('présence mobile : un employé en doublon dans la grille est refusé en bloc', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    $res = pushAttendance([
        ['employee_id' => $employee->id, 'status' => 'present'],
        ['employee_id' => $employee->id, 'status' => 'absent'],
    ]);

    expect($res->json('results.0.status'))->toBe('validation_failed');
    expect(EmployeeAttendance::count())->toBe(0);
});

test('présence mobile : une date future est refusée', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    $res = pushAttendance(
        [['employee_id' => $employee->id, 'status' => 'present']],
        now()->addDay()->toDateString(),
    );

    expect($res->json('results.0.status'))->toBe('validation_failed');
    expect(EmployeeAttendance::count())->toBe(0);
});

test('présence mobile : sans droit rh.C, refus non rejouable', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->readonlyUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->readonlyUser);

    $res = pushAttendance([['employee_id' => $employee->id, 'status' => 'present']]);

    expect($res->json('results.0.status'))->toBe('permission_denied');
    expect(EmployeeAttendance::count())->toBe(0);
});

test('la grille web de présence emprunte la même Action que le terrain', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    // Terrain d'abord, puis correction par la grille web : une seule ligne.
    pushAttendance([['employee_id' => $employee->id, 'status' => 'absent']])->assertOk();

    $this->post(route('attendance.store'), [
        'date'   => now()->toDateString(),
        'status' => [$employee->id => 'present'],
    ])->assertRedirect();

    expect(EmployeeAttendance::where('employee_id', $employee->id)->count())->toBe(1);
    expect(EmployeeAttendance::where('employee_id', $employee->id)->value('status'))->toBe('present');
});

// ───────────────────── PULL : ÉQUIPE ET CONGÉS ─────────────────────

test('pull : les congés validés courants descendent, sans aucun motif', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    $current = EmployeeLeave::create([
        'employee_id' => $employee->id, 'type' => 'maladie',
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
        'days_count' => 5, 'status' => 'approuve', 'reason' => 'Motif médical confidentiel',
    ]);
    // Ancien congé : hors fenêtre, inutile au rassemblement du matin.
    EmployeeLeave::create([
        'employee_id' => $employee->id, 'type' => 'conge_annuel',
        'start_date' => now()->subMonths(6)->toDateString(),
        'end_date' => now()->subMonths(6)->addDays(5)->toDateString(),
        'days_count' => 6, 'status' => 'approuve',
    ]);
    // Demande non validée : elle ne vaut pas absence justifiée.
    EmployeeLeave::create([
        'employee_id' => $employee->id, 'type' => 'sans_solde',
        'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        'days_count' => 2, 'status' => 'en_attente',
    ]);

    $res = $this->getJson('/api/v1/sync/pull');
    $res->assertOk();

    $leaves = $res->json('entities.employee_leaves.upserts');
    expect($leaves)->toHaveCount(1);
    expect($leaves[0]['id'])->toBe($current->id);
    expect(array_keys($leaves[0]))
        ->toBe(['id', 'employee_id', 'start_date', 'end_date', 'status', 'updated_at']);
});

test('pull : l’équipe descend au pointeur RH, sans données de paie', function () {
    Employee::factory()->create(['status' => 'Actif', 'salary' => 3_000_000]);

    $res = $this->getJson('/api/v1/sync/pull');
    $employees = $res->json('entities.employees.upserts');

    expect($employees)->toHaveCount(1);
    foreach (['salary', 'contract_type', 'emergency_contact_phone', 'birth_date', 'phone'] as $forbidden) {
        expect($employees[0])->not->toHaveKey($forbidden);
    }
});

// ───────────────────── PULL : TARIFS ET CATALOGUE POS ─────────────────────

test('pull : le barème du client descend pour re-tarifer hors réseau', function () {
    $stock = Stock::create([
        'item_name' => 'Filet de poulet', 'category' => 'Produits finis',
        'unit' => 'kg', 'current_quantity' => 12.4, 'alert_threshold' => 2,
    ]);
    $product = Product::create([
        'name' => 'Filet de poulet', 'sku' => '1201', 'product_type' => 'decoupe',
        'stock_id' => $stock->id, 'unit' => 'kg', 'base_price' => 55000, 'is_active' => true,
    ]);

    $grossiste = SalePriceList::create(['name' => 'Grossiste', 'is_default' => false]);
    SalePriceListItem::create([
        'sale_price_list_id' => $grossiste->id, 'product_id' => $product->id,
        'product_type' => 'decoupe', 'unit_price' => 47000,
    ]);
    $client = Client::create([
        'client_id' => 'CL-001', 'name' => 'Boucherie du marché',
        'type' => 'professionnel', 'price_list_id' => $grossiste->id,
    ]);

    $res = $this->getJson('/api/v1/sync/pull');
    $res->assertOk();

    // Le client porte son tarif : le POS mobile résout la cascade en local.
    $clients = collect($res->json('entities.clients.upserts'))->keyBy('id');
    expect($clients[$client->id]['price_list_id'])->toBe($grossiste->id);

    expect($res->json('entities.sale_price_lists.upserts'))->toHaveCount(1);
    $items = $res->json('entities.sale_price_list_items.upserts');
    expect($items)->toHaveCount(1);
    expect((float) $items[0]['unit_price'])->toBe(47000.0);
    expect($items[0]['product_id'])->toBe($product->id);
});

test('pull : le catalogue porte le PLU et le stock vendable', function () {
    $stock = Stock::create([
        'item_name' => 'Cuisse', 'category' => 'Produits finis',
        'unit' => 'kg', 'current_quantity' => 0.4, 'alert_threshold' => 0,
    ]);
    Product::create([
        'name' => 'Cuisse de poulet', 'sku' => '1305', 'product_type' => 'decoupe',
        'stock_id' => $stock->id, 'unit' => 'kg', 'base_price' => 38000,
        'is_favorite' => true, 'is_active' => true,
    ]);
    // Article non adossé au magasin : vendable librement (quantité null).
    Product::create([
        'name' => 'Prestation abattage', 'sku' => '9001', 'product_type' => 'service',
        'unit' => 'unite', 'base_price' => 1500, 'is_active' => true,
    ]);

    $res = $this->getJson('/api/v1/sync/pull');
    $products = collect($res->json('entities.products.upserts'))->keyBy('sku');

    expect((float) $products['1305']['available_quantity'])->toBe(0.4);
    expect($products['1305']['is_favorite'])->toBeTrue();
    expect($products['1305']['unit'])->toBe('kg');
    // Non suivi en stock : null, PAS zéro — zéro bloquerait la vente à tort.
    expect($products['9001']['available_quantity'])->toBeNull();
});

test('la cascade de prix est bien article → catégorie → prix de base', function () {
    // Ce test FIGE l'ordre de la cascade côté serveur. Le POS mobile la rejoue
    // hors réseau dans mobile/src/offline/pricing.ts : changer l'ordre ici sans
    // mettre à jour ce miroir ferait vendre au terrain à un autre prix qu'au
    // comptoir — d'où ce garde-fou.
    $list = SalePriceList::create(['name' => 'Demi-gros', 'is_default' => true]);
    $client = Client::create(['client_id' => 'CL-9', 'name' => 'Alimentation Kaloum',
        'type' => 'professionnel', 'price_list_id' => $list->id]);

    $product = Product::create(['name' => 'Œufs plateau', 'product_type' => 'oeufs',
        'unit' => 'plateau', 'base_price' => 40000, 'is_active' => true]);

    // 3. Aucune ligne de tarif → prix de base.
    expect(SalePriceList::priceForProduct($client, $product))->toBe(40000.0);

    // 2. Ligne CATÉGORIE → elle prime sur le prix de base.
    SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_id' => null,
        'product_type' => 'oeufs', 'unit_price' => 36000]);
    expect(SalePriceList::priceForProduct($client, $product))->toBe(36000.0);

    // 1. Ligne ARTICLE → elle prime sur la catégorie.
    SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_id' => $product->id,
        'product_type' => 'oeufs', 'unit_price' => 34500]);
    expect(SalePriceList::priceForProduct($client, $product))->toBe(34500.0);

    // Client sans tarif → tarif marqué « par défaut » (client de comptoir).
    $comptoir = Client::create(['client_id' => 'CL-10', 'name' => 'Comptoir', 'type' => 'particulier']);
    expect(SalePriceList::priceForProduct($comptoir, $product))->toBe(34500.0);
});

test('pull : un vendeur sans droit RH ne reçoit ni équipe ni congés', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    EmployeeLeave::create([
        'employee_id' => $employee->id, 'type' => 'maladie',
        'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        'days_count' => 2, 'status' => 'approuve',
    ]);

    // Rôle commerce seul : lecture commerce, aucun droit RH ni élevage.
    $role = \App\Models\Role::create([
        'name' => 'vendeur-m6', 'label' => 'Vendeur', 'display_name' => 'Vendeur',
        'permissions' => ['L', 'C'],
    ]);
    foreach (\App\Models\Module::all() as $module) {
        $allowed = $module->slug === 'commerce';
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $module->id],
            ['can_read' => $allowed, 'can_create' => $allowed, 'can_modify' => false,
             'can_delete' => false, 'created_at' => now(), 'updated_at' => now()],
        );
    }
    $seller = \App\Models\User::factory()->create(['role_id' => $role->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $seller->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($seller);
    \Laravel\Sanctum\Sanctum::actingAs($seller);

    $res = $this->getJson('/api/v1/sync/pull');
    $res->assertOk();

    expect($res->json('entities.employees.upserts'))->toBe([]);
    expect($res->json('entities.employee_leaves.upserts'))->toBe([]);
    // Mais il reçoit bien ce qu'il faut pour vendre.
    expect($res->json('entities.sale_price_lists'))->not->toBeNull();
});
