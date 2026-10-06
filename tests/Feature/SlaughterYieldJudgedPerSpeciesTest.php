<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\Species;
use App\Models\User;
use App\Services\SlaughterService;
use Database\Seeders\SpeciesSeeder;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RENDEMENT CARCASSE SE JUGE PAR ESPÈCE, SUR LE TABLEAU DE BORD AUSSI.
 *
 * L'enregistrement d'un abattage jugeait déjà chaque rendement sur les bornes de
 * SON espèce (ovins 45-52 %, volaille 70-75 %). Le tableau de bord, lui,
 * moyennait toutes les espèces et jugeait ce chiffre sur les bornes de la
 * volaille : des ovins à 48 % — un bon rendement — mettaient la tuile au rouge.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
});

function abattage(string $espece, string $type, float $rendement): void
{
    $sp = Species::where('slug', $espece)->first();
    $lot = Batch::factory()->create(['species_id' => $sp->id, 'production_type_id' => ProductionType::resolveOrCreate($type, $sp->id)->id, 'current_quantity' => 100]);
    $ordre = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $lot->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 10, 'status' => 'termine',
        'requested_by' => User::query()->orderBy('id')->value('id'),
    ]);
    SlaughterResult::create([
        'slaughter_order_id' => $ordre->id, 'total_carcass_weight_kg' => 100, 'carcass_yield_percent' => $rendement,
        'condemned_count' => 0, 'avg_live_weight_kg' => 40, 'avg_carcass_weight_kg' => 20, 'execution_date' => now()->toDateString(),
    ]);
}

test('des ovins à 48 % et des poulets à 72 % sont tous deux dans leur norme', function () {
    abattage('mouton', 'engraissement', 48);
    abattage('poulet', 'chair', 72);

    $parFamille = app(SlaughterService::class)->getKPI(30)['yield_by_family'];

    expect($parFamille['petit_ruminant']['status'])->toBe('ok')
        ->and($parFamille['petit_ruminant']['target_min'])->toBe(45)
        ->and($parFamille['volaille']['status'])->toBe('ok');
});

test('des poulets à 60 % restent en alerte — non-régression', function () {
    abattage('poulet', 'chair', 60);

    expect(app(SlaughterService::class)->getKPI(30)['yield_by_family']['volaille']['status'])->toBe('alerte');
});

test('la tuile du tableau de bord affiche une ligne par famille, sur ses bornes', function () {
    abattage('mouton', 'engraissement', 48);

    $html = $this->actingAs($this->adminUser)->get(route('slaughter.dashboard'))->assertOk()->getContent();

    expect(str_contains($html, '45-52%'))->toBeTrue();
});

test('le terrain reçoit les morceaux de l’ESPÈCE de chaque ordre — pas des ailes pour un ovin', function () {
    abattage('mouton', 'engraissement', 48);
    abattage('poulet', 'chair', 72);
    \Illuminate\Support\Facades\DB::table('farm_user')->insertOrIgnore(['farm_id' => session('current_farm_id'), 'user_id' => $this->adminUser->id, 'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
    \Laravel\Sanctum\Sanctum::actingAs($this->adminUser);

    $ordres = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.slaughter_orders.upserts'));
    $codes = fn (string $espece) => collect($ordres->first(fn ($o) => SlaughterOrder::find($o['id'])->batch->species->slug === $espece)['cuts'])->pluck('code');

    expect($codes('mouton'))->toContain('gigot')->not->toContain('aile')
        ->and($codes('poulet'))->toContain('aile');
});
