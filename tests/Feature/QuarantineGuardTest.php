<?php

use App\Actions\Batch\TransferBatch;
use App\Actions\EggProduction\RecordEggCollection;
use App\Actions\Sale\CreateSale;
use App\Models\Batch;
use App\Models\Building;
use App\Models\EggProduction;
use App\Models\HealthIncident;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Audit 360° — propagation de la QUARANTAINE sanitaire au lot (biosécurité).
 *
 * Un incident santé OUVERT avec is_quarantined gèle CÔTÉ SERVEUR :
 * - la vente à la tête   (ValidateSale::destockBatch),
 * - la mutation           (TransferBatch — vecteur de propagation n°1),
 * - la collecte d'œufs    (RecordEggCollection — délai d'attente médicamenteux).
 * La levée passe exclusivement par le circuit santé (résolution / toggle).
 * Décision produit : blocage dur (pas de dérogation à la confirmation).
 */

beforeEach(function () {
    $this->setUpRbac();

    // Bande pondeuse en âge de ponte (l'invariant d'âge ne doit pas masquer
    // celui de quarantaine) avec un effectif rond pour lire les impacts.
    $this->pondeuse = Batch::factory()->create([
        'production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id,
        'arrival_date'       => now()->subDays(200),
        'initial_quantity'   => 100,
        'current_quantity'   => 100,
        'qty_alive'          => 100,
    ]);

    // Place le lot en quarantaine via un incident ouvert (même écriture que
    // HealthIncidentController::toggleQuarantine).
    $this->quarantine = function (): HealthIncident {
        return HealthIncident::create([
            'building_id'           => $this->pondeuse->building_id,
            'batch_id'              => $this->pondeuse->id,
            'user_id'               => $this->managerUser->id,
            'incident_date'         => now()->toDateString(),
            'mortality_count'       => 3,
            'symptoms'              => 'Prostration, diarrhée blanche',
            'severity'              => HealthIncident::SEVERITY_CRITICAL,
            'status'                => HealthIncident::STATUS_PENDING,
            'is_quarantined'        => true,
            'quarantine_started_at' => now(),
        ]);
    };

    // Vente BROUILLON de 5 sujets vifs à la tête, adossée au lot.
    $this->draftHeadSale = function (): Sale {
        $client = [
            'client_id'  => 'CLI-Q001',
            'name'       => 'Client Quarantaine',
            'type'       => 'particulier',
            'category'   => 'detaillant',
            'status'     => 'actif',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('clients', 'farm_id')) {
            $client['farm_id'] = $this->farm->id;
        }
        $clientId = DB::table('clients')->insertGetId($client);

        $this->actingAs($this->managerUser);

        return app(CreateSale::class)->execute([
            'client_id' => $clientId,
            'sale_date' => now()->toDateString(),
            'type'      => 'bon_livraison',
            'items'     => [[
                'product_type' => 'animal_vif',
                'product_name' => 'Poule réformée',
                'batch_id'     => $this->pondeuse->id,
                'quantity'     => 5,
                'unit'         => 'tete',
                'unit_price'   => 60000,
            ]],
        ]);
    };
});

test('vente à la tête d\'un lot en quarantaine : refusée, effectif et statut intacts', function () {
    ($this->quarantine)();
    $sale = ($this->draftHeadSale)();

    $this->actingAs($this->managerUser)
        ->put(route('sales.validate', $sale))
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('brouillon');
    expect($this->pondeuse->fresh()->current_quantity)->toBe(100);
});

test('mutation d\'un lot en quarantaine : refusée, lot immobile', function () {
    ($this->quarantine)();

    $cible    = Building::factory()->create(['type' => 'ponte', 'capacity' => 5000, 'status' => 'Disponible']);
    $protocol = Protocol::create(['name' => 'Protocole ponte test', 'type' => 'ponte']);
    $origine  = $this->pondeuse->building_id;

    expect(fn () => app(TransferBatch::class)->execute($this->pondeuse, [
        'target_building_id' => $cible->id,
        'new_protocol_id'    => $protocol->id,
        'new_phase'          => 'ponte',
        'transfer_date'      => now()->toDateString(),
    ]))->toThrow(Exception::class, 'QUARANTAINE');

    expect($this->pondeuse->fresh()->building_id)->toBe($origine);
});

test('collecte d\'œufs d\'un lot en quarantaine : refusée, aucune production créée', function () {
    ($this->quarantine)();

    expect(fn () => app(RecordEggCollection::class)->execute([
        'batch_id'             => $this->pondeuse->id,
        'production_date'      => now()->toDateString(),
        'total_eggs_collected' => 80,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ]))->toThrow(ValidationException::class);

    expect(EggProduction::where('batch_id', $this->pondeuse->id)->count())->toBe(0);
});

test('levée de la quarantaine (résolution incident) : collecte et vente repassent', function () {
    $incident = ($this->quarantine)();

    // Même écriture que HealthIncidentController::resolve.
    $incident->update([
        'status'              => HealthIncident::STATUS_RESOLVED,
        'is_quarantined'      => false,
        'quarantine_ended_at' => now(),
    ]);

    $production = app(RecordEggCollection::class)->execute([
        'batch_id'             => $this->pondeuse->id,
        'production_date'      => now()->toDateString(),
        'total_eggs_collected' => 80,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ]);
    expect($production->total_eggs_collected)->toBe(80);

    $sale = ($this->draftHeadSale)();
    $this->actingAs($this->managerUser)
        ->put(route('sales.validate', $sale))
        ->assertSessionHas('success');

    expect($sale->fresh()->status)->toBe('valide');
    expect($this->pondeuse->fresh()->current_quantity)->toBe(95);
});

test('un incident résolu encore flaggé is_quarantined ne bloque plus (double sécurité du scope)', function () {
    $incident = ($this->quarantine)();

    // Cas limite : résolution qui aurait oublié de baisser le flag — le scope
    // exclut les incidents résolus, la quarantaine est considérée levée.
    $incident->update(['status' => HealthIncident::STATUS_RESOLVED]);

    expect($this->pondeuse->fresh()->isQuarantined())->toBeFalse();
    expect($this->pondeuse->fresh()->activeQuarantine())->toBeNull();
});
