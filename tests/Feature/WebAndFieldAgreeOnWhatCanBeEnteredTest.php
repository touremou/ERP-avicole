<?php

use App\Models\AnimalBreed;
use App\Models\Batch;
use App\Models\Building;
use App\Models\FeedPurchase;
use App\Models\HealthCheck;
use App\Models\ProductionType;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use App\Support\ProfilsMetier;
use App\Support\SitesDuCompte;
use App\Support\SuiviParEspece;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE BUREAU ET LE TERRAIN ACCEPTENT LA MÊME CHOSE.
 *
 * Signalé par l'exploitation :
 *   • à la réception d'aliment, les listes du bureau devenaient du texte libre
 *     sur le téléphone — fautes de frappe, articles et fournisseurs en double ;
 *   • un technicien d'élevage faisait les saisies du magasinier ;
 *   • la fiche d'un lot affichait « Démarrage » pour toujours ;
 *   • les formulaires de suivi proposaient la même chose à un mouton qu'à une poule.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();
    $this->lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Actif', 'type' => 'chair']);
    // Le terrain agit sur le site du compte (cloisonnement multi-sites).
    SitesDuCompte::rattacher($this->adminUser, [$this->farm->id]);
    Sanctum::actingAs($this->adminUser);
});

function receptionTerrain(Tests\TestCase $test, array $champs): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'feed_purchase.create',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(), 'purchase_date' => today()->toDateString(),
            'quantity' => 10, 'unit_price' => 2_500_000, 'unit' => 'Sac', 'payment_mode' => 'comptant',
            'metadata' => ['bag_weight' => 50],
        ], $champs),
    ]]])->assertOk()->json('results.0');
}

// ── 1. CHAMPS RESTREINTS ────────────────────────────────────────────────

test('réception d’aliment au terrain : l’aliment et le fournisseur viennent des listes du bureau', function () {
    $faute = receptionTerrain($this, ['batch_id' => $this->lot->id, 'feed_type' => 'demarage chair', 'supplier' => $this->provider->name]);
    $inconnu = receptionTerrain($this, ['batch_id' => $this->lot->id, 'feed_type' => 'Chair Démarrage', 'supplier' => 'Avipro Guinee SARL']);

    expect($faute['status'])->not->toBe('success')
        ->and($inconnu['status'])->not->toBe('success')
        ->and(FeedPurchase::count())->toBe(0)
        ->and(Provider::where('name', 'Avipro Guinee SARL')->exists())->toBeFalse();

    $bon = receptionTerrain($this, ['batch_id' => $this->lot->id, 'feed_type' => 'Chair Démarrage', 'supplier' => $this->provider->name]);
    expect($bon['status'])->toBe('success');
});

test('le bureau tient la même règle, et le téléphone reçoit la liste des aliments du lot', function () {
    $this->actingAs($this->adminUser)->post(route('feed-purchases.store'), [
        'batch_id' => $this->lot->id, 'purchase_date' => today()->toDateString(), 'feed_type' => 'Ponte 1 (Pic de ponte)',
        'quantity' => 1, 'unit' => 'Sac', 'unit_price' => 100000, 'supplier' => $this->provider->name,
    ])->assertSessionHasErrors('feed_type');

    $lots = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.batches.upserts');
    $lot = collect($lots)->firstWhere('id', $this->lot->id);
    expect($lot['feed_phases'])->toBe($this->lot->feedPhases())
        ->and($lot)->toHaveKeys(['current_phase', 'species_family']);
});

test('le mode d’administration d’un soin est UNE liste, au bureau comme au terrain', function () {
    $soin = fn (string $mode) => $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'health_check.create',
        'payload' => ['uuid' => (string) Str::uuid(), 'batch_id' => $this->lot->id, 'type' => 'Vaccin',
            'product_name' => 'Gumboro', 'mode_administration' => $mode, 'intervention_date' => today()->toDateString(),
            'withdrawal_days' => 0],
    ]]])->assertOk()->json('results.0');

    // L'ancien libellé du terrain est ramené à celui du bureau, pas refusé.
    expect($soin('Pulvérisation')['status'])->toBe('success')
        ->and(HealthCheck::latest('id')->value('mode_administration'))->toBe('Spray')
        ->and($soin('à la louche')['status'])->not->toBe('success');
});

// ── 2. LES SAISIES SUIVENT LE PROFIL ────────────────────────────────────

test('le technicien d’élevage consulte le stock, il ne fait pas les saisies du magasinier', function () {
    $technicien = User::factory()->create(['role_id' => Role::where('name', 'profil-technicien-elevage')->value('id')]);
    SitesDuCompte::rattacher($technicien, [$this->farm->id]);

    expect($technicien->canModule('logistique', 'L'))->toBeTrue()
        ->and($technicien->canModule('logistique', 'C'))->toBeFalse();

    app('auth')->forgetGuards();
    Sanctum::actingAs($technicien);
    $refus = receptionTerrain($this, ['batch_id' => $this->lot->id, 'feed_type' => 'Chair Démarrage', 'supplier' => $this->provider->name]);
    expect($refus['status'])->toBe('permission_denied');
});

test('la révision atteint le profil déjà installé', function () {
    $role = Role::where('name', 'profil-technicien-elevage')->first();
    $cellule = \App\Models\ModulePermission::where('role_id', $role->id)
        ->where('module_id', \App\Models\Module::where('slug', 'logistique')->value('id'));
    $cellule->update(['can_create' => true]);

    (include database_path('migrations/2026_11_12_000002_technician_reads_stock_only.php'))->up();

    expect($cellule->value('can_create'))->toBeFalse()->and($cellule->value('can_read'))->toBeTrue();
});

// ── 3. LE STADE SUIT L'ÂGE ──────────────────────────────────────────────

test('le stade d’un lot se calcule : âge × durée du cycle de sa souche, réglable par l’administrateur', function () {
    $mouton = Species::where('slug', 'mouton')->firstOrFail();
    $type = ProductionType::where('species_id', $mouton->id)->where('slug', 'engraissement')->firstOrFail();
    $souche = AnimalBreed::create(['species_id' => $mouton->id, 'name' => 'Embouche courte', 'cycle_days' => 100]);
    $bergerie = Building::factory()->create(['type' => 'mixte', 'capacity' => 100, 'status' => Building::STATUS_DISPONIBLE]);
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $bergerie->id, 'status' => 'Actif',
        'species_id' => $mouton->id, 'production_type_id' => $type->id, 'breed_id' => $souche->id,
        'arrival_date' => today()->subDays(10)->toDateString(), 'birth_date' => today()->subDays(10)->toDateString()]);

    expect($lot->fresh()->current_phase)->toBe('Démarrage');

    $lot->update(['arrival_date' => today()->subDays(50)->toDateString(), 'birth_date' => today()->subDays(50)->toDateString()]);
    expect($lot->fresh()->current_phase)->toBe('Croissance');

    $lot->update(['arrival_date' => today()->subDays(90)->toDateString(), 'birth_date' => today()->subDays(90)->toDateString()]);
    expect($lot->fresh()->current_phase)->toBe('Finition');

    // L'administrateur allonge le cycle de la souche : le même lot revient en croissance.
    $this->actingAs($this->adminUser)->put(route('admin.breeds.update', $souche), ['name' => 'Embouche courte', 'cycle_days' => 300]);
    expect($lot->fresh()->current_phase)->toBe('Croissance');

    // La fiche affiche le stade calculé, plus la valeur figée à la création.
    $lot->update(['production_phase' => 'demarrage']);
    $this->actingAs($this->adminUser)->get(route('batches.show', $lot))->assertOk()->assertSee('Croissance');
});

// ── 4. LE SUIVI S'ADAPTE À L'ESPÈCE ─────────────────────────────────────

test('chaque famille a son suivi, servi au téléphone dans la session', function () {
    $volaille = SuiviParEspece::pour('volaille');
    $mouton = SuiviParEspece::pour('petit_ruminant');

    expect($volaille['symptomes'])->toContain('Chute de ponte')
        ->and($mouton['symptomes'])->not->toContain('Chute de ponte')->toContain('Ballonnement')
        ->and($mouton['modes'])->not->toContain('Oculaire')
        ->and($mouton['masquer'])->toContain('picage', 'litiere')
        ->and($mouton['produits']['Vaccin'])->toContain('PPR (peste des petits ruminants)')
        ->and(SuiviParEspece::pour('lagomorphe')['produits']['Vaccin'])->toContain('Myxomatose');

    // Aucune famille n'offre un mode que le serveur refuserait, ni ne masque une rubrique inconnue.
    foreach (SuiviParEspece::referentiel() as $famille => $suivi) {
        expect(array_diff($suivi['modes'], SuiviParEspece::MODES))->toBe([], "modes de {$famille}")
            ->and(array_diff($suivi['masquer'], SuiviParEspece::RUBRIQUES))->toBe([], "rubriques de {$famille}");
    }

    $me = $this->getJson('/api/v1/auth/me')->assertOk()->json();
    expect($me['settings']['species_forms'])->toHaveKeys(['defaut', 'volaille', 'petit_ruminant', 'lagomorphe']);
});

// ── 5. NI LOT NI BÂTIMENT VIRTUEL AU TERRAIN ────────────────────────────

test('le téléphone ne reçoit ni le lot ni le bâtiment virtuels de traçabilité', function () {
    // Œufs achetés à l'extérieur : lot à effectif nul dans la zone virtuelle (StartIncubation).
    $zone = Building::factory()->create(['farm_id' => $this->farm->id, 'name' => Building::ZONE_VIRTUELLE]);
    $virtuel = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $zone->id,
        'status' => 'Actif', 'initial_quantity' => 0, 'current_quantity' => 0]);
    // Un lot annulé descend encore : le téléphone doit apprendre son statut.
    $annule = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Annulé']);

    $entites = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');
    $lots = collect($entites['batches']['upserts'])->pluck('id');
    $batiments = collect($entites['buildings']['upserts'])->pluck('id');

    expect($lots)->toContain($this->lot->id)->toContain($annule->id)->not->toContain($virtuel->id)
        ->and($batiments)->toContain($this->building->id)->not->toContain($zone->id);
});

// ── 6. LA MISE EN LOT NE LANCE QUE LES ESPÈCES ACTIVÉES ─────────────────

test('une espèce désactivée ne se lance ni au terrain ni au bureau, et le téléphone le sait', function () {
    $porc = Species::create(['slug' => 'porc-test', 'name_fr' => 'Porc', 'family' => 'porcin', 'is_active' => false]);
    $engraissement = ProductionType::create(['species_id' => $porc->id, 'slug' => 'engraissement-test',
        'name_fr' => 'Engraissement', 'is_active' => true]);

    $types = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.production_types.upserts'));
    expect($types->firstWhere('id', $engraissement->id))->toMatchArray(['utilisable' => false, 'species_name' => 'Porc']);

    $terrain = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'batch.upsert',
        'payload' => ['uuid' => (string) Str::uuid(), 'code' => 'PORC-01', 'type' => 'engraissement-test',
            'production_type_id' => $engraissement->id, 'building_id' => $this->building->id,
            'initial_quantity' => 20, 'current_quantity' => 20, 'arrival_date' => today()->toDateString(), 'updated_at' => now()->toIso8601String()],
    ]]])->assertOk()->json('results.0');
    expect($terrain['status'])->not->toBe('success')
        ->and(json_encode($terrain, JSON_UNESCAPED_UNICODE))->toContain('espèce est désactivée')
        ->and(Batch::where('code', 'PORC-01')->exists())->toBeFalse();

    expect(ProductionType::refusDeMiseEnLot($engraissement->fresh('species')))->not->toBeNull();
    $porc->update(['is_active' => true]);
    expect(ProductionType::refusDeMiseEnLot($engraissement->fresh('species')))->toBeNull();
});

// ── 7. CHAQUE OPÉRATION NE LISTE QUE LES LOTS QUI LA PERMETTENT ─────────

test('le téléphone sait quels lots se traient, et reçoit chaque jour les lots actifs', function () {
    $termine = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Terminé']);

    $lot = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.batches.upserts'))
        ->firstWhere('id', $this->lot->id);
    expect($lot['tracks_milk'])->toBeFalse(); // un lot de poulets de chair ne se trait pas

    // Synchronisation suivante, rien n'a changé en base : le lot ACTIF
    // redescend quand même (sa ponte et son stade dépendent de la date) ;
    // le lot terminé, non.
    $this->travel(1)->days();
    $ids = collect($this->getJson('/api/v1/sync/pull?since='.urlencode(now()->subHour()->toIso8601String()))
        ->assertOk()->json('entities.batches.upserts'))->pluck('id');
    expect($ids)->toContain($this->lot->id)->not->toContain($termine->id);
});
