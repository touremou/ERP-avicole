<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropProtocol;
use App\Models\Plot;
use App\Services\CropProtocolAlertService;
use Database\Seeders\CropProtocolSeeder;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function protocolPayload(array $overrides = []): array
{
    return array_merge([
        'name'      => 'Itinéraire Maïs test',
        'crop_name' => 'Maïs',
        'agro_zone' => 'haute_guinee',
        'source'    => 'IRAG/FAO (indicatif)',
        'is_active' => 1,
        'items'     => [
            ['day_number' => 0,  'stage' => 'Semis',   'action_name' => 'Semis + NPK de fond', 'type' => 'fertilisation', 'product_suggested' => 'NPK 15-15-15', 'dose' => '150 kg/ha'],
            ['day_number' => 30, 'stage' => 'Croissance', 'action_name' => "Apport d'urée", 'type' => 'fertilisation', 'product_suggested' => 'Urée 46%', 'dose' => '100 kg/ha'],
            ['day_number' => 100, 'stage' => 'Maturation', 'action_name' => 'Récolte', 'type' => 'recolte'],
        ],
    ], $overrides);
}

function protocolCycle(int $farmId, ?int $protocolId, string $plantingDate): CropCycle
{
    $plot = Plot::create(['farm_id' => $farmId, 'name' => 'Parcelle proto', 'area_ha' => 2, 'status' => Plot::STATUS_EN_CULTURE]);

    return CropCycle::create([
        'farm_id'          => $farmId,
        'plot_id'          => $plot->id,
        'crop_name'        => 'Maïs',
        'area_used_ha'     => 2,
        'planting_date'    => $plantingDate,
        'crop_protocol_id' => $protocolId,
    ]);
}

test('un manager peut créer un protocole avec ses étapes', function () {
    $this->actingAs($this->managerUser)
        ->post(route('crop-protocols.store'), protocolPayload())
        ->assertRedirect();

    $protocol = CropProtocol::where('name', 'Itinéraire Maïs test')->firstOrFail();
    expect($protocol->items()->count())->toBe(3)
        ->and($protocol->crop_name)->toBe('Maïs')
        ->and($protocol->agro_zone)->toBe('haute_guinee');
});

test('un manager peut mettre à jour un protocole (étapes remplacées)', function () {
    $this->actingAs($this->managerUser)->post(route('crop-protocols.store'), protocolPayload());
    $protocol = CropProtocol::where('name', 'Itinéraire Maïs test')->firstOrFail();

    $this->actingAs($this->managerUser)
        ->put(route('crop-protocols.update', $protocol), protocolPayload([
            'name'  => 'Itinéraire Maïs révisé',
            'items' => [
                ['day_number' => 5, 'stage' => 'Levée', 'action_name' => 'Contrôle levée', 'type' => 'observation'],
            ],
        ]))
        ->assertRedirect(route('crop-protocols.show', $protocol));

    expect($protocol->fresh()->name)->toBe('Itinéraire Maïs révisé')
        ->and($protocol->items()->count())->toBe(1)
        ->and($protocol->items()->first()->action_name)->toBe('Contrôle levée');
});

test('un lecteur seul ne peut pas créer de protocole, un admin peut le supprimer', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('crop-protocols.store'), protocolPayload())
        ->assertSessionHas('error');
    expect(CropProtocol::where('name', 'Itinéraire Maïs test')->exists())->toBeFalse();

    $protocol = CropProtocol::create(['name' => 'À supprimer', 'crop_name' => 'Riz']);
    $this->actingAs($this->adminUser)
        ->delete(route('crop-protocols.destroy', $protocol))
        ->assertRedirect(route('crop-protocols.index'));
    expect(CropProtocol::find($protocol->id))->toBeNull();
});

test('attacher un protocole à un cycle persiste crop_protocol_id', function () {
    $protocol = CropProtocol::create(['name' => 'Proto cycle', 'crop_name' => 'Maïs']);
    $cycle = protocolCycle($this->farm->id, null, now()->subMonth()->toDateString());

    $this->actingAs($this->managerUser)
        ->put(route('crop-cycles.update', $cycle), [
            'crop_name'        => 'Maïs',
            'area_used_ha'     => 2,
            'planting_date'    => now()->subMonth()->toDateString(),
            'crop_protocol_id' => $protocol->id,
            'status'           => CropCycle::STATUS_EN_COURS,
        ])
        ->assertRedirect(route('crop-cycles.show', $cycle));

    expect($cycle->fresh()->crop_protocol_id)->toBe($protocol->id);
});

test('le calendrier projeté distingue les étapes en retard et réalisées', function () {
    $protocol = CropProtocol::create(['name' => 'Proto échéancier', 'crop_name' => 'Maïs']);
    $protocol->items()->createMany([
        ['day_number' => 0,  'action_name' => 'Semis + NPK de fond', 'type' => 'fertilisation', 'product_suggested' => 'NPK 15-15-15'],
        ['day_number' => 30, 'action_name' => "Apport d'urée", 'type' => 'fertilisation', 'product_suggested' => 'Urée 46%'],
        ['day_number' => 200, 'action_name' => 'Récolte', 'type' => 'recolte'],
    ]);

    // Cycle semé il y a 60 jours : J0 et J30 sont passées, J200 à venir.
    $cycle = protocolCycle($this->farm->id, $protocol->id, now()->subDays(60)->toDateString());

    // L'étape J0 (NPK) a été réalisée : un intrant correspondant est saisi.
    CropInput::create([
        'farm_id'       => $this->farm->id,
        'crop_cycle_id' => $cycle->id,
        'type'          => 'engrais',
        'name'          => 'NPK 15-15-15',
        'input_date'    => now()->subDays(58)->toDateString(),
    ]);

    $schedule = collect((new CropProtocolAlertService())->getCycleSchedule($cycle->fresh()))
        ->keyBy(fn ($e) => $e['item']->day_number);

    expect($schedule[0]['status'])->toBe('done')       // NPK saisi
        ->and($schedule[30]['status'])->toBe('overdue') // urée prévue J30, non faite
        ->and($schedule[200]['status'])->toBe('upcoming');
});

test('une étape en retard produit une alerte critique', function () {
    $protocol = CropProtocol::create(['name' => 'Proto alerte', 'crop_name' => 'Maïs']);
    $protocol->items()->create(['day_number' => 10, 'action_name' => 'Sarclage', 'type' => 'sarclage', 'stage' => 'Levée']);

    $cycle = protocolCycle($this->farm->id, $protocol->id, now()->subDays(40)->toDateString());

    $alerts = (new CropProtocolAlertService())->getCycleAlerts($cycle->fresh());

    expect($alerts)->not->toBeEmpty()
        ->and($alerts[0]['severity'])->toBe('critique')
        ->and($alerts[0]['type'])->toBe('protocol')
        ->and($alerts[0]['title'])->toContain('Sarclage');
});

test('la fiche cycle affiche la section itinéraire technique quand un protocole est rattaché', function () {
    $protocol = CropProtocol::create(['name' => 'Proto fiche', 'crop_name' => 'Maïs']);
    $protocol->items()->create(['day_number' => 0, 'action_name' => 'Semis', 'type' => 'semis']);

    $cycle = protocolCycle($this->farm->id, $protocol->id, now()->subDays(10)->toDateString());

    $this->actingAs($this->managerUser)
        ->get(route('crop-cycles.show', $cycle))
        ->assertOk()
        ->assertSee('Itinéraire technique');
});

test('le seeder de protocoles crée des itinéraires de référence et reste idempotent', function () {
    $this->seed(CropProtocolSeeder::class);
    $countFirst = CropProtocol::count();
    $maisItems = CropProtocol::where('crop_name', 'Maïs')->first()?->items()->count();

    expect($countFirst)->toBeGreaterThanOrEqual(5);
    foreach (['Riz', 'Maïs', 'Tomate', 'Pomme de terre', 'Oignon', 'Arachide'] as $crop) {
        expect(CropProtocol::where('crop_name', $crop)->exists())->toBeTrue();
    }

    $this->seed(CropProtocolSeeder::class);
    expect(CropProtocol::count())->toBe($countFirst)
        ->and(CropProtocol::where('crop_name', 'Maïs')->first()->items()->count())->toBe($maisItems);
});

test('un manager peut valider une étape d\'itinéraire, qui passe à « done »', function () {
    $protocol = CropProtocol::create(['name' => 'Proto valid', 'crop_name' => 'Maïs']);
    // Sarclage sans intrant associé : l'inférence ne le détecterait jamais.
    $item = $protocol->items()->create(['day_number' => 15, 'action_name' => 'Sarclage manuel', 'type' => 'sarclage', 'stage' => 'Levée']);

    $cycle = protocolCycle($this->farm->id, $protocol->id, now()->subDays(20)->toDateString());

    // Avant validation : étape en retard (J+15 dépassé), non faite.
    $before = collect((new CropProtocolAlertService())->getCycleSchedule($cycle->fresh()))
        ->firstWhere('item.id', $item->id);
    expect($before['status'])->toBe('overdue');

    $this->actingAs($this->managerUser)
        ->post(route('crop-cycles.steps.complete', [$cycle, $item]), ['notes' => 'Fait par l\'équipe'])
        ->assertRedirect();

    $this->assertDatabaseHas('crop_protocol_completions', [
        'crop_cycle_id' => $cycle->id, 'crop_protocol_item_id' => $item->id, 'notes' => 'Fait par l\'équipe',
    ]);

    $after = collect((new CropProtocolAlertService())->getCycleSchedule($cycle->fresh()))
        ->firstWhere('item.id', $item->id);
    expect($after['status'])->toBe('done')
        ->and($after['completion'])->not->toBeNull()
        ->and($after['completion']->completed_by)->toBe($this->managerUser->id);
});

test('valider deux fois la même étape ne crée pas de doublon (idempotent)', function () {
    $protocol = CropProtocol::create(['name' => 'Proto idemp', 'crop_name' => 'Maïs']);
    $item = $protocol->items()->create(['day_number' => 5, 'action_name' => 'Observation', 'type' => 'observation']);
    $cycle = protocolCycle($this->farm->id, $protocol->id, now()->subDays(10)->toDateString());

    $this->actingAs($this->managerUser)->post(route('crop-cycles.steps.complete', [$cycle, $item]));
    $this->actingAs($this->managerUser)->post(route('crop-cycles.steps.complete', [$cycle, $item]));

    expect(\App\Models\CropProtocolCompletion::where('crop_cycle_id', $cycle->id)->where('crop_protocol_item_id', $item->id)->count())->toBe(1);
});

test('un manager peut annuler la validation d\'une étape (réouverture)', function () {
    $protocol = CropProtocol::create(['name' => 'Proto annul', 'crop_name' => 'Maïs']);
    $item = $protocol->items()->create(['day_number' => 5, 'action_name' => 'Irrigation', 'type' => 'irrigation']);
    $cycle = protocolCycle($this->farm->id, $protocol->id, now()->subDays(10)->toDateString());

    $this->actingAs($this->managerUser)->post(route('crop-cycles.steps.complete', [$cycle, $item]));
    $this->actingAs($this->managerUser)->delete(route('crop-cycles.steps.uncomplete', [$cycle, $item]))->assertRedirect();

    $this->assertDatabaseMissing('crop_protocol_completions', [
        'crop_cycle_id' => $cycle->id, 'crop_protocol_item_id' => $item->id,
    ]);
});

test('on ne peut pas valider une étape étrangère à l\'itinéraire du cycle', function () {
    $protoA = CropProtocol::create(['name' => 'Proto A', 'crop_name' => 'Maïs']);
    $cycle  = protocolCycle($this->farm->id, $protoA->id, now()->subDays(10)->toDateString());

    // Étape appartenant à un AUTRE protocole.
    $protoB = CropProtocol::create(['name' => 'Proto B', 'crop_name' => 'Riz']);
    $alien  = $protoB->items()->create(['day_number' => 2, 'action_name' => 'Repiquage', 'type' => 'semis']);

    $this->actingAs($this->managerUser)
        ->post(route('crop-cycles.steps.complete', [$cycle, $alien]))
        ->assertSessionHas('error');

    $this->assertDatabaseMissing('crop_protocol_completions', ['crop_protocol_item_id' => $alien->id]);
});
