<?php

use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\Employee;
use App\Models\Reception;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

/** Fabrique une expédition « expedie » avec une ligne non stockée (pas de déstockage). */
function makeDispatch(int $farmId, int $dispatchedBy, ?int $receiverId = null): Dispatch
{
    $dispatch = Dispatch::create([
        'farm_id'              => $farmId,
        'dispatch_number'      => 'EXP-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'dispatched_by'        => $dispatchedBy,
        'intended_receiver_id' => $receiverId,
        'driver_name'          => 'Sory Camara',
        'dispatch_date'        => now()->toDateString(),
        'destination'          => 'Dépôt Conakry',
        'status'               => 'expedie',
    ]);

    DispatchItem::create([
        'farm_id'             => $farmId,
        'dispatch_id'         => $dispatch->id,
        'product_type'        => 'autre',
        'product_name'        => 'Matériel divers',
        'quantity_dispatched' => 10,
        'unit'                => 'piece',
        'condition_at_dispatch' => 'bon',
    ]);

    return $dispatch->fresh('items');
}

function receptionPayload(Dispatch $dispatch): array
{
    return [
        'reception_date' => now()->toDateString(),
        'items' => $dispatch->items->map(fn ($it) => [
            'dispatch_item_id'  => $it->id,
            'quantity_received' => $it->quantity_dispatched,
            'quantity_damaged'  => 0,
            'condition'         => 'bon',
        ])->all(),
    ];
}

test('le récepteur désigné (sans droit M) peut valider sa réception', function () {
    // operatorUser = L,C (PAS de logistique.M). Expédié par le manager.
    $dispatch = makeDispatch($this->farm->id, $this->managerUser->id, $this->operatorUser->id);

    $this->actingAs($this->operatorUser)
        ->post(route('dispatches.reception.store', $dispatch), receptionPayload($dispatch))
        ->assertRedirect(route('dispatches.show', $dispatch));

    expect(Reception::where('dispatch_id', $dispatch->id)->exists())->toBeTrue();
});

test('un responsable logistique.M valide en secours même sans être désigné', function () {
    // Aucun récepteur désigné ; expédié par l'operator. Le manager (M) valide.
    $dispatch = makeDispatch($this->farm->id, $this->operatorUser->id, null);

    $this->actingAs($this->managerUser)
        ->post(route('dispatches.reception.store', $dispatch), receptionPayload($dispatch))
        ->assertRedirect(route('dispatches.show', $dispatch));

    expect(Reception::where('dispatch_id', $dispatch->id)->exists())->toBeTrue();
});

test('un utilisateur ni désigné ni habilité ne peut pas réceptionner', function () {
    // readonlyUser = L seulement, et n'est pas le récepteur désigné.
    $dispatch = makeDispatch($this->farm->id, $this->managerUser->id, $this->operatorUser->id);

    $this->actingAs($this->readonlyUser)
        ->post(route('dispatches.reception.store', $dispatch), receptionPayload($dispatch))
        ->assertSessionHas('error');

    expect(Reception::where('dispatch_id', $dispatch->id)->exists())->toBeFalse();
});

test('anti-fraude : l\'expéditeur ne peut pas valider même s\'il est désigné récepteur', function () {
    // Cas limite : la même personne est expéditeur ET récepteur désigné.
    $dispatch = makeDispatch($this->farm->id, $this->operatorUser->id, $this->operatorUser->id);

    $this->actingAs($this->operatorUser)
        ->post(route('dispatches.reception.store', $dispatch), receptionPayload($dispatch))
        ->assertSessionHas('error');

    expect(Reception::where('dispatch_id', $dispatch->id)->exists())->toBeFalse();
});

test('la création d\'une expédition avec récepteur déclenche la notification', function () {
    $employee = Employee::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->operatorUser->id, 'status' => 'Actif']);

    $hub = $this->mock(\App\Services\NotificationHub::class);
    $hub->shouldReceive('notifyDispatchReceiver')->once();

    $this->actingAs($this->managerUser)
        ->post(route('dispatches.store'), [
            'driver_name'          => 'Mamadou Bah',
            'dispatch_date'        => now()->toDateString(),
            'destination'          => 'Magasin Kindia',
            'intended_receiver_id' => $this->operatorUser->id,
            'items' => [[
                'product_type' => 'autre',
                'product_name' => 'Caisses vides',
                'quantity'     => 5,
                'unit'         => 'piece',
            ]],
        ])
        ->assertRedirect();

    $dispatch = Dispatch::first();
    expect($dispatch->intended_receiver_id)->toBe($this->operatorUser->id);
});
