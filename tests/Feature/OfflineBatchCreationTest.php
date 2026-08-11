<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Provider;
use App\Models\Species;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * MISE EN LOT DEPUIS LE TERRAIN, SANS RÉSEAU.
 *
 * Le serveur savait déjà recevoir l'arrivée d'un lot hors ligne : le type
 * `batch.upsert` existe avec son validateur et sa résolution de conflit. Mais
 * AUCUN écran ne l'émettait — la fonctionnalité était complète d'un côté et
 * inatteignable de l'autre. Déclarer une arrivée exigeait un passage au bureau ;
 * à Kérouané, sans réseau, les chiffres du jour J se reconstituaient de mémoire.
 *
 * Ces tests exercent le CONTRAT que l'écran doit respecter, et la limite qu'il
 * annonce à l'opérateur : un lot créé hors ligne n'a pas encore d'identifiant
 * serveur, donc aucune autre saisie ne peut s'y rattacher avant la première
 * synchronisation. L'écran le dit ; il ne le laisse pas découvrir.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'API est sans session : SetApiFarmContext résout la ferme courante depuis le
    // pivot `farm_user`. Sans rattachement, l'en-tête X-Farm-Id est ignoré et les
    // règles `farmScopedExists` refusent le bâtiment — ce qui vaut aussi en
    // production pour un compte oublié dans l'affectation des sites.
    foreach ([$this->adminUser, $this->readonlyUser] as $user) {
        Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
            ['farm_id' => $this->farm->id, 'user_id' => $user->id],
            ['is_owner' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    $this->building = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'P1', 'type' => 'Poulailler',
        'status' => Building::STATUS_VIDE, 'capacity' => 500,
    ]);

    $this->species = Species::where('slug', 'poulet')->first();

    Sanctum::actingAs($this->adminUser);
});

/**
 * Charge utile minimale acceptée par SyncService::batchUpsert.
 *
 * Le bâtiment est passé en PARAMÈTRE : les propriétés posées sur $this dans
 * beforeEach ne sont pas accessibles depuis une fonction globale de Pest.
 */
function arrivalPayload(int $buildingId, array $overrides = []): array
{
    return array_merge([
        'uuid'             => (string) Illuminate\Support\Str::uuid(),
        'code'             => 'CHA-260811',
        'type'             => 'chair',
        'building_id'      => $buildingId,
        'initial_quantity' => 300,
        'current_quantity' => 295,
        'qty_dead'         => 5,
        'arrival_mortality_rate' => 1.67,
        'status'           => 'Actif',
        'arrival_date'     => now()->toDateString(),
        'buy_price_per_unit' => 5000,
        'updated_at'       => now()->toIso8601String(),
    ], $overrides);
}

/**
 * Pousse l'opération comme le fait la PWA — avec l'en-tête X-Farm-Id.
 *
 * L'API est sans session : c'est cet en-tête qui établit le contexte de ferme
 * (SetApiFarmContext), et sans lui les règles `farmScopedExists` refusent le
 * bâtiment. Ce n'est pas un détail de test : un client qui l'omettrait verrait
 * toutes ses saisies rejetées.
 */
function pushArrival(Tests\TestCase $test, int $farmId, int $buildingId, array $overrides = []): array
{
    return $test->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Illuminate\Support\Str::uuid(),
            'type'    => 'batch.upsert',
            'payload' => arrivalPayload($buildingId, $overrides),
        ]],
    ], ['X-Farm-Id' => (string) $farmId])->assertOk()->json('results.0');
}

test('une arrivée déclarée au terrain crée le lot au serveur', function () {
    $result = pushArrival($this, $this->farm->id, $this->building->id);

    expect($result['status'])->toBe('success');

    $batch = Batch::withoutGlobalScopes()->where('code', 'CHA-260811')->first();

    expect($batch)->not->toBeNull()
        ->and($batch->initial_quantity)->toBe(300)
        // L'effectif VIVANT : les morts du transport sont déduits d'emblée.
        ->and($batch->current_quantity)->toBe(295)
        ->and($batch->qty_dead)->toBe(5)
        ->and((float) $batch->buy_price_per_unit)->toBe(5000.0)
        // Le coût d'acquisition suit, sans quoi le coût de revient partirait faux.
        ->and((float) $batch->total_acquisition_cost)->toBe(300 * 5000.0);
});

test('le rejeu de la même arrivée ne crée pas un second lot', function () {
    // Le terrain pousse plusieurs fois quand le réseau hésite. L'upsert porte sur
    // l'UUID : deux appareils hors ligne ne peuvent pas s'écraser l'un l'autre, et
    // un même appareil ne peut pas doubler son lot.
    $uuid = (string) Illuminate\Support\Str::uuid();

    pushArrival($this, $this->farm->id, $this->building->id, ['uuid' => $uuid]);
    pushArrival($this, $this->farm->id, $this->building->id, ['uuid' => $uuid, 'initial_quantity' => 300]);

    expect(Batch::withoutGlobalScopes()->where('uuid', $uuid)->count())->toBe(1);
});

test('un lot est refusé sur un bâtiment d’une AUTRE ferme', function () {
    // Étanchéité multi-sites : un technicien de Kindia ne met pas un lot chez
    // Kérouané, même en forgeant la requête.
    $foreign = \App\Models\Farm::create(['code' => 'ETR-1', 'name' => 'Autre site', 'is_active' => true]);

    $foreignBuilding = Building::withoutGlobalScopes()->create([
        'farm_id' => $foreign->id, 'name' => 'Etranger', 'type' => 'Poulailler',
        'status' => Building::STATUS_VIDE, 'capacity' => 500,
    ]);

    $result = pushArrival($this, $this->farm->id, $foreignBuilding->id);

    expect($result['status'])->toBe('validation_failed');
});

test('un compte SANS droit de création d’élevage est refusé', function () {
    Sanctum::actingAs($this->readonlyUser);

    expect(pushArrival($this, $this->farm->id, $this->building->id)['status'])->toBe('permission_denied');
});

test('le fournisseur et le responsable restent facultatifs', function () {
    // Au terrain, on ne connaît pas toujours le fournisseur au moment du
    // déchargement. Exiger le champ ferait remettre la saisie à plus tard — c'est
    //-à-dire à jamais.
    $result = pushArrival($this, $this->farm->id, $this->building->id, ['employee_id' => null, 'provider_id' => null]);

    expect($result['status'])->toBe('success');
});

test('un fournisseur connu est bien rattaché', function () {
    $provider = Provider::factory()->create(['name' => 'Couvoir Kindia', 'status' => 'Actif']);

    pushArrival($this, $this->farm->id, $this->building->id, ['provider_id' => $provider->id]);

    $batch = Batch::withoutGlobalScopes()->where('code', 'CHA-260811')->first();

    expect($batch->provider_id)->toBe($provider->id);
});

test('l’écran est déclaré dans le routeur ET dans la carte des droits', function () {
    // La parité écran / droit est déjà verrouillée par RbacMobileAccessTest ; on
    // vérifie ici que le nouvel écran y figure, et qu'il exige le MÊME droit que
    // l'opération qu'il émet. Un écran plus permissif que son opération ferait
    // remplir un formulaire pour rien.
    $access = file_get_contents(base_path('mobile/src/offline/access.ts'));
    $router = file_get_contents(base_path('mobile/src/app/App.tsx'));

    expect($access)->toContain("'/elevage/mise-en-lot': 'elevage.C'")
        ->and($access)->toContain("'batch.upsert': 'elevage.C'")
        ->and($router)->toContain("['/elevage/mise-en-lot', <NewBatchScreen />]");
});

test('l’écran ANNONCE sa limite au lieu de la laisser découvrir', function () {
    // Le point d'honnêteté de cette fonctionnalité. Un lot créé hors ligne n'a pas
    // d'identifiant serveur, et toutes les autres opérations en exigent un : le
    // pointage n'est donc possible qu'après la première synchronisation. Une
    // saisie qui échoue en silence est le défaut le plus signalé par cette
    // exploitation — on le prévient par un message, pas par un commentaire.
    $screen = file_get_contents(base_path('mobile/src/features/elevage/NewBatchScreen.tsx'));

    expect($screen)->toContain('après la première synchronisation')
        // …et le cas « référentiels absents » est traité, sinon l'écran offrirait
        // des listes vides sans expliquer pourquoi.
        ->and($screen)->toContain('Référentiels absents en local');
});

test('les autres opérations exigent bien un identifiant serveur', function () {
    // La raison de la limite, vérifiée sur le contrat plutôt qu'affirmée : si un
    // jour le pointage acceptait un uuid de lot, la limite tomberait et le message
    // de l'écran deviendrait faux.
    $service = file_get_contents(app_path('Services/Sync/SyncService.php'));

    expect($service)->toContain("'batch_id'             => ['required', 'integer', \$this->farmScopedExists('batches')]");
});
