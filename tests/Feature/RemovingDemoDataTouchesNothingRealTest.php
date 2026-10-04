<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\CropCycle;
use App\Models\Plot;
use App\Models\User;
use Database\Seeders\CultureDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RETIRER LES DONNÉES DE DÉMONSTRATION — et rien d'autre.
 *
 * Le système sait ce qu'il a semé ; il peut le retirer sans toucher au réel.
 * Le risque est ailleurs : « Bâtiment A », « P-NORD », une vraie ferme peut les
 * avoir choisis. Un élément n'est donc reconnu que s'il porte TOUS les
 * attributs semés, et un élément reconnu qui porte un historique réel est
 * GARDÉ. Tout part ici du VRAI semeur — la leçon de #396 : une garde éprouvée
 * sur un décor ne garde rien.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(DatabaseSeeder::class);
    $this->seed(CultureDemoSeeder::class);
});

function retirerLaDemo(object $test, bool $force = true)
{
    return $test->artisan('avismart:remove-demo-data', $force ? ['--force' => true] : []);
}

function batimentADeDemonstration(): ?Building
{
    return Building::withoutGlobalScopes()->withTrashed()->where('name', 'Bâtiment A')->where('capacity', 3000)->where('type', 'chair')->first();
}

test('la SIMULATION liste ce qui partirait, et ne touche à rien', function () {
    $avant = [User::withoutGlobalScopes()->count(), Building::withoutGlobalScopes()->count(), CropCycle::withoutGlobalScopes()->withTrashed()->count(), Plot::withoutGlobalScopes()->count()];

    retirerLaDemo($this, force: false)
        ->expectsOutputToContain('simulation, rien n’est modifié')
        ->expectsOutputToContain('admin@avismart.com')
        ->assertSuccessful();

    expect([User::withoutGlobalScopes()->count(), Building::withoutGlobalScopes()->count(), CropCycle::withoutGlobalScopes()->withTrashed()->count(), Plot::withoutGlobalScopes()->count()])->toBe($avant);
});

test('les comptes de démonstration partent tous', function () {
    retirerLaDemo($this)->assertSuccessful();

    expect(User::withoutGlobalScopes()->whereIn('email', UserSeeder::emailsDeDemonstration())->exists())->toBeFalse();
});

test('« Bâtiment A » part à la CORBEILLE — donc restaurable', function () {
    retirerLaDemo($this)->assertSuccessful();

    $batiment = batimentADeDemonstration();

    expect($batiment)->not->toBeNull()
        ->and($batiment->trashed())->toBeTrue();
});

test('les données de culture de démonstration partent, saisies comprises', function () {
    $cycles = CropCycle::withoutGlobalScopes()->whereIn('code', ['CY-MAIS-01', 'CY-MANIOC-01', 'CY-TOMATE-01'])->pluck('id');
    $parcelles = Plot::withoutGlobalScopes()->whereIn('code', ['P-NORD', 'P-SUD', 'P-EST'])->pluck('id');
    $gari = DB::table('crop_recipes')->where('code', 'REC-GARI')->value('id');
    expect($cycles)->toHaveCount(3)
        ->and(DB::table('weather_readings')->whereIn('plot_id', $parcelles)->exists())->toBeTrue()
        ->and(DB::table('crop_recipe_items')->where('crop_recipe_id', $gari)->exists())->toBeTrue();

    retirerLaDemo($this)->assertSuccessful();

    expect(CropCycle::withoutGlobalScopes()->withTrashed()->whereKey($cycles)->exists())->toBeFalse()
        ->and(DB::table('crop_inputs')->whereIn('crop_cycle_id', $cycles)->exists())->toBeFalse()
        ->and(DB::table('harvests')->whereIn('crop_cycle_id', $cycles)->exists())->toBeFalse()
        ->and(Plot::withoutGlobalScopes()->whereIn('code', ['P-NORD', 'P-SUD', 'P-EST'])->exists())->toBeFalse()
        ->and(DB::table('crop_recipes')->where('code', 'REC-GARI')->exists())->toBeFalse()
        // Ce qui COMPOSAIT ces éléments part avec eux : relevés météo des
        // parcelles, lignes de la recette. La première version les comptait
        // comme un historique réel, et gardait parcelle et recette.
        ->and(DB::table('weather_readings')->whereIn('plot_id', $parcelles)->exists())->toBeFalse()
        ->and(DB::table('crop_recipe_items')->where('crop_recipe_id', $gari)->exists())->toBeFalse();
});

test('un VRAI cycle codé « CY-MAIS-01 » sur une vraie parcelle : gardé', function () {
    /*
     * Le code du cycle ne suffit pas non plus : un cycle n'est « de
     * démonstration » que s'il est AUSSI sur une parcelle de démonstration.
     */
    $vraie = Plot::withoutGlobalScopes()->create(['farm_id' => 1, 'code' => 'CH-01', 'name' => 'Champ du marigot', 'status' => 'en_culture']);
    $reel = CropCycle::withoutGlobalScopes()->where('code', 'CY-MAIS-01')->first()->replicate();
    $reel->plot_id = $vraie->id;
    $reel->save();

    retirerLaDemo($this)->assertSuccessful();

    expect(CropCycle::withoutGlobalScopes()->whereKey($reel->id)->exists())->toBeTrue();
});

test('le RÉFÉRENTIEL n’est pas touché', function () {
    /*
     * Le catalogue des cultures, les espèces, les normes, les réglages sont
     * aussi semés — mais ce sont des référentiels, pas une démonstration.
     */
    $avant = collect(['species', 'crop_species', 'production_norms', 'settings', 'modules', 'roles'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

    retirerLaDemo($this)->assertSuccessful();

    foreach ($avant as $table => $n) {
        expect(DB::table($table)->count())->toBe($n, "{$table} a été touchée");
    }
});

test('« Bâtiment A » qui ABRITE un lot est un vrai bâtiment : gardé', function () {
    /*
     * LA borne : une ferme peut avoir nommé son premier bâtiment « Bâtiment A ».
     * Dès qu'il porte un historique, ce n'est plus la démonstration.
     */
    Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => batimentADeDemonstration()->id]);

    retirerLaDemo($this)->expectsOutputToContain('GARDÉ')->assertSuccessful();

    expect(batimentADeDemonstration()->trashed())->toBeFalse();
});

test('« Bâtiment A » dont la capacité a été CORRIGÉE est un vrai bâtiment : gardé', function () {
    /*
     * Pas encore d'historique, mais l'exploitant l'a ajusté à son parc réel :
     * ce n'est plus l'élément semé. Le nom seul ne suffit pas à le reconnaître.
     */
    batimentADeDemonstration()->update(['capacity' => 1500]);

    retirerLaDemo($this)->assertSuccessful();

    expect(Building::withoutGlobalScopes()->where('name', 'Bâtiment A')->first()->trashed())->toBeFalse();
});

test('une vraie parcelle codée « P-NORD » mais autrement NOMMÉE : gardée', function () {
    // Le code seul ne suffit pas à reconnaître la démonstration.
    Plot::withoutGlobalScopes()->where('code', 'P-NORD')->update(['name' => 'Champ du marigot']);

    retirerLaDemo($this)->assertSuccessful();

    expect(Plot::withoutGlobalScopes()->where('code', 'P-NORD')->exists())->toBeTrue();
});

test('une parcelle de démo qui porte un VRAI cycle : gardée, la démo autour part', function () {
    /*
     * La ferme a lancé la démo, puis cultivé pour de vrai sur « Parcelle Nord ».
     * Les cycles de démonstration partent ; la parcelle, qui porte un cycle
     * réel, reste.
     */
    $nord = Plot::withoutGlobalScopes()->where('code', 'P-NORD')->first();
    $reel = CropCycle::withoutGlobalScopes()->where('code', 'CY-MAIS-01')->first()->replicate();
    $reel->code = 'CYC-2026-0001';
    $reel->plot_id = $nord->id;
    $reel->save();

    retirerLaDemo($this)->assertSuccessful();

    expect(Plot::withoutGlobalScopes()->whereKey($nord->id)->exists())->toBeTrue()
        ->and(CropCycle::withoutGlobalScopes()->where('code', 'CYC-2026-0001')->exists())->toBeTrue()
        ->and(CropCycle::withoutGlobalScopes()->where('code', 'CY-MAIS-01')->exists())->toBeFalse();
});

test('sans démonstration, la commande ne trouve rien et ne fait rien', function () {
    retirerLaDemo($this)->assertSuccessful();
    $apres = [User::withoutGlobalScopes()->count(), Building::withoutGlobalScopes()->withTrashed()->count(), Plot::withoutGlobalScopes()->count()];

    retirerLaDemo($this)->assertSuccessful();

    expect([User::withoutGlobalScopes()->count(), Building::withoutGlobalScopes()->withTrashed()->count(), Plot::withoutGlobalScopes()->count()])->toBe($apres);
});
