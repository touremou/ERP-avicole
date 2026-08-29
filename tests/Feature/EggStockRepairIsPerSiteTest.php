<?php

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Farm;
use App\Models\ProductionType;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA REPRISE DU MAGASIN D'ŒUFS EMPILAIT LES QUATRE SITES SUR UN SEUL.
 *
 * `FarmScope` ne s'applique QUE si `session('current_farm_id')` est défini. En
 * console il ne l'est pas. Sans le poser, `eggs:repair-stock` :
 *
 *   • additionnait les tris de TOUS les sites en un seul chiffre attendu ;
 *   • les comparait aux entrées d'UN seul article de stock — le premier trouvé ;
 *   • écrivait le manque sur la ferme PAR DÉFAUT (BelongsToFarm assigne
 *     `Farm::defaultId()` à la création, faute de session).
 *
 * Les œufs triés à Kindia et à Kérouané se seraient donc retrouvés sur le
 * magasin d'un seul site, les autres restant vides.
 *
 * ─── POURQUOI C'ÉTAIT INVISIBLE ───
 *
 * Sur une exploitation MONO-SITE, le résultat est juste : il n'y a qu'une ferme,
 * l'agrégat global est l'agrégat du site. Le défaut ne se manifeste que là où on
 * ne teste pas — et c'est une commande de RÉPARATION, donc lancée précisément
 * quand la donnée est déjà en souffrance.
 *
 * ─── LE MOTIF EXISTAIT DÉJÀ ───
 *
 * `stocks:sync` et `maintenance:check` posent la session en bouclant sur les
 * sites, avec ce commentaire : « le scope ferme se règle sur la session, y
 * compris en console : sans cela, chaque site verrait les lots de tous les
 * autres ». Cette commande-ci ne le faisait pas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->autreSite = Farm::create([
        'code' => 'FT-KER', 'name' => 'Kérouané', 'is_active' => true,
    ]);
});

/** Une collecte TRIÉE sur le site voulu, portant $alveoles alvéoles de calibre L. */
function collecteTriee(int $farmId, int $buildingId, int $userId, float $alveoles): EggProduction
{
    $typePonte = ProductionType::where('slug', 'ponte')->firstOrFail();

    $lot = Batch::factory()->create([
        'farm_id'            => $farmId,
        'building_id'        => $buildingId,
        'production_type_id' => $typePonte->id,
        'arrival_date'       => today()->subDays(200)->toDateString(),
        'birth_date'         => today()->subDays(200)->toDateString(),
        'initial_quantity'   => 500,
        'current_quantity'   => 500,
        'status'             => 'Actif',
    ]);

    return EggProduction::create([
        'farm_id'              => $farmId,
        'batch_id'             => $lot->id,
        'production_date'      => today()->subDay()->toDateString(),
        'total_eggs_collected' => $alveoles * 30,
        'is_graded'            => true,
        'grade_l'              => $alveoles,
        'user_id'              => $userId,
    ]);
}

/** Le stock d'œufs « L » d'un site donné, toutes portées confondues. */
function stockOeufsL(int $farmId): float
{
    return (float) Stock::withoutGlobalScopes()
        ->where('farm_id', $farmId)
        ->where('item_name', 'L')
        ->where('category', Stock::CAT_OEUFS)
        ->sum('current_quantity');
}

test('chaque site reçoit SES œufs, et pas ceux du voisin', function () {
    /*
     * LE défaut. 100 alvéoles triées ici, 40 là-bas : chaque magasin doit
     * recevoir les siennes — et non 140 sur l'un et zéro sur l'autre.
     */
    collecteTriee($this->farm->id, $this->building->id, $this->adminUser->id, 100);

    // Un bâtiment pour l'autre site, puis sa collecte.
    $batimentAilleurs = \App\Models\Building::withoutGlobalScopes()->create([
        'farm_id'  => $this->autreSite->id,
        'name'     => 'Poulailler KER-1',
        'type'     => 'ponte',
        'capacity' => 1000,
    ]);

    collecteTriee($this->autreSite->id, $batimentAilleurs->id, $this->adminUser->id, 40);

    $this->artisan('eggs:repair-stock --force')->assertExitCode(0);

    expect(stockOeufsL($this->farm->id))->toBe(100.0)
        ->and(stockOeufsL($this->autreSite->id))->toBe(40.0);
});

test('un site SANS tri ne reçoit rien', function () {
    /*
     * LA borne : la boucle ne doit pas créer d'article vide, ni déverser sur un
     * site ce qu'un autre a trié.
     */
    collecteTriee($this->farm->id, $this->building->id, $this->adminUser->id, 60);

    $this->artisan('eggs:repair-stock --force')->assertExitCode(0);

    expect(stockOeufsL($this->farm->id))->toBe(60.0)
        ->and(stockOeufsL($this->autreSite->id))->toBe(0.0);
});

test('elle reste IDEMPOTENTE site par site', function () {
    /*
     * La propriété que l'en-tête de la commande met en avant, et que la boucle
     * ne doit pas casser : relancer n'ajoute rien.
     */
    collecteTriee($this->farm->id, $this->building->id, $this->adminUser->id, 75);

    $this->artisan('eggs:repair-stock --force')->assertExitCode(0);
    $this->artisan('eggs:repair-stock --force')->assertExitCode(0);

    expect(stockOeufsL($this->farm->id))->toBe(75.0);
});

test('l’option --farm limite la reprise à un seul site', function () {
    collecteTriee($this->farm->id, $this->building->id, $this->adminUser->id, 50);

    $batimentAilleurs = \App\Models\Building::withoutGlobalScopes()->create([
        'farm_id'  => $this->autreSite->id,
        'name'     => 'Poulailler KER-2',
        'type'     => 'ponte',
        'capacity' => 1000,
    ]);

    collecteTriee($this->autreSite->id, $batimentAilleurs->id, $this->adminUser->id, 30);

    $this->artisan('eggs:repair-stock --force --farm=' . $this->farm->id)->assertExitCode(0);

    expect(stockOeufsL($this->farm->id))->toBe(50.0)
        ->and(stockOeufsL($this->autreSite->id))->toBe(0.0);   // pas touché
});
