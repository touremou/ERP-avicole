<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\BatchAdvisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * AUTONOMIE FOURRAGÈRE DU TROUPEAU DE RUMINANTS, EN MATIÈRE SÈCHE.
 *
 * Décision de l'exploitation :
 *   Autonomie (jours) = stock utile (kg MS) ÷ consommation du troupeau (kg MS/j),
 *   alerte sous 15 jours.
 *
 * Ce qui se mesurait : le stock en kg BRUTS, divisé par la ration de la seule
 * bande affichée — ration exprimée, elle, en kg de MS. Un ensilage à 35 % de
 * MS comptait presque trois fois ses jours, et deux bandes au même râtelier
 * croyaient chacune avoir tout le stock pour elles.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);

    $type = ProductionType::where('slug', 'reproducteur')
        ->whereHas('species', fn ($q) => $q->where('slug', 'mouton'))->firstOrFail();

    $bande = fn (string $code) => Batch::factory()->create([
        'code' => $code, 'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'production_type_id' => $type->id, 'type' => 'reproducteur', 'model_name' => 'Bélier Djallonké',
        'arrival_date' => today()->subDays(3), 'birth_date' => today()->subYears(2),
        'status' => 'Actif', 'initial_quantity' => 20, 'current_quantity' => 20,
    ]);

    $this->bandeA = $bande('BEL-A');
    $this->bandeB = $bande('BEL-B');
    $this->ration = $this->bandeA->feedPhases()[0];

    // Le besoin de chaque bande, au barème (kg MS/j).
    $conseiller = app(BatchAdvisorService::class);
    $this->besoinA = (float) $conseiller->recommendation($this->bandeA)['total']['feed_kg'];
    $this->besoinB = (float) $conseiller->recommendation($this->bandeB)['total']['feed_kg'];
});

function alimentDuRatelier(int $ferme, string $nom, float $kg, ?float $ms, ?string $secteur = null): Stock
{
    return Stock::create([
        'item_name' => $nom, 'feed_type' => $nom, 'category' => 'conso', 'unit' => 'KG',
        'current_quantity' => $kg, 'alert_threshold' => 0, 'farm_id' => $ferme,
        'metadata' => array_filter(['dry_matter_pct' => $ms, 'poultry_type' => $secteur], fn ($v) => $v !== null),
    ]);
}

function autonomieDuTroupeau(): ?array
{
    return app(BatchAdvisorService::class)->autonomieDuTroupeau();
}

test('le stock se compte en kg de matière sèche', function () {
    alimentDuRatelier($this->farm->id, $this->ration, 1000, 35);

    $autonomie = autonomieDuTroupeau();

    expect($autonomie['stock_kg'])->toEqual(350.0)
        ->and($autonomie['stock_brut_kg'])->toEqual(1000.0)
        ->and($autonomie['unite'])->toBe('kg MS')
        ->and($autonomie['days'])->toBe((int) floor(350 / ($this->besoinA + $this->besoinB)));
});

test('deux bandes au même râtelier : l’autonomie du troupeau, la même sur chaque fiche', function () {
    alimentDuRatelier($this->farm->id, $this->ration, 2000, 88);

    $conseiller = app(BatchAdvisorService::class);
    $surA = $conseiller->feedAutonomy($this->bandeA);
    $surB = $conseiller->feedAutonomy($this->bandeB);

    expect($surA['troupeau'])->toBeTrue()
        ->and($surA['daily_kg'])->toEqual(round($this->besoinA + $this->besoinB, 1))
        ->and($surA['days'])->toBe($surB['days'])
        ->and($surA['lots'])->toEqualCanonicalizing(['BEL-A', 'BEL-B']);
});

test('alerte sous 15 jours, attention sous un mois, et le seuil se règle', function () {
    expect(Stock::niveauAutonomieFourragere(14))->toBe('critique')
        ->and(Stock::niveauAutonomieFourragere(15))->toBe('attention')
        ->and(Stock::niveauAutonomieFourragere(29))->toBe('attention')
        ->and(Stock::niveauAutonomieFourragere(30))->toBeNull();

    Setting::set('stocks.forage_autonomy_alert_days', '20');
    expect(Stock::niveauAutonomieFourragere(19))->toBe('critique');
});

test('dix jours de foin : la fiche du lot est en alerte critique, avec sa date de rupture', function () {
    $besoin = $this->besoinA + $this->besoinB;
    alimentDuRatelier($this->farm->id, $this->ration, $besoin * 10.5 / 0.85, 85);

    $autonomie = app(BatchAdvisorService::class)->feedAutonomy($this->bandeA);

    expect($autonomie['days'])->toBe(10)
        ->and($autonomie['is_critical'])->toBeTrue()
        ->and($autonomie['rupture_le']->toDateString())->toBe(today()->addDays(10)->toDateString());
});

test('un aliment sans teneur en MS compte brut, et c’est dit', function () {
    alimentDuRatelier($this->farm->id, $this->ration, 500, null);

    $autonomie = autonomieDuTroupeau();

    expect($autonomie['stock_kg'])->toEqual(500.0)
        ->and($autonomie['sans_teneur_ms'])->toBe([$this->ration]);
});

test('l’aliment des poulets n’entre pas au râtelier, même sous un nom libre', function () {
    alimentDuRatelier($this->farm->id, 'Provende maison', 3000, 88, 'Chair');

    expect(autonomieDuTroupeau())->toBeNull();
});

test('au tableau de bord : une alerte fourragère, et le même foin n’est pas rejugé en jours de silo', function () {
    $foin = alimentDuRatelier($this->farm->id, $this->ration, ($this->besoinA + $this->besoinB) * 5, 100);
    // Des sorties qui, au rythme du silo, donneraient un jour d'autonomie.
    StockMovement::create(['farm_id' => $this->farm->id, 'stock_id' => $foin->id, 'type' => 'out',
        'user_id' => $this->adminUser->id, 'quantity' => (float) $foin->current_quantity * 30]);

    $page = $this->get(route('dashboard'))->assertOk();

    expect(collect($page->viewData('priorityAlerts'))->pluck('title'))->toContain('Autonomie fourragère')
        ->and(collect($page->viewData('criticalTypes'))->pluck('type'))->not->toContain($this->ration);
});

test('la fiche de l’article enregistre sa teneur en matière sèche', function () {
    $this->post(route('stocks.store'), [
        'item_name' => 'Foin de brachiaria', 'category' => 'conso', 'unit' => 'KG', 'alert_threshold' => 0,
        'metadata' => ['conso_type' => 'Aliment', 'poultry_type' => 'Reproducteur', 'dry_matter_pct' => '85'],
    ])->assertSessionHasNoErrors();

    expect(Stock::where('item_name', 'Foin de brachiaria')->first()->teneurMatiereSeche())->toEqual(85.0);

    $this->post(route('stocks.store'), [
        'item_name' => 'Ensilage', 'category' => 'conso', 'unit' => 'KG', 'alert_threshold' => 0,
        'metadata' => ['conso_type' => 'Aliment', 'dry_matter_pct' => '135'],
    ])->assertSessionHasErrors('metadata.dry_matter_pct');
});

test('une bande de volaille garde l’autonomie de son silo — non-régression', function () {
    $poulets = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'type' => 'chair',
        'model_name' => 'Ross 308', 'arrival_date' => today()->subDays(10),
        'status' => 'Actif', 'initial_quantity' => 500, 'current_quantity' => 500,
    ]);
    alimentDuRatelier($this->farm->id, 'Chair Démarrage', 1000, null, 'Chair');

    $autonomie = app(BatchAdvisorService::class)->feedAutonomy($poulets);

    expect($autonomie)->not->toBeNull()
        ->and($autonomie)->not->toHaveKey('troupeau')
        ->and($autonomie['stock_kg'])->toEqual(1000.0);
});

test('le dosage du matin dit la ration en matière sèche, et l’autonomie du troupeau', function () {
    alimentDuRatelier($this->farm->id, $this->ration, 2000, 88);

    app(\App\Services\NotificationHub::class)->sendFeedingDosage();

    $message = (string) \Illuminate\Support\Facades\DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)->latest()->value('data');

    expect($message)->toContain('kg MS* ration')
        ->and($message)->toContain('kg MS, troupeau');
});
