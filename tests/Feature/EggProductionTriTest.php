<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Farm;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tri par calibre + application réelle du paramètre production.egg_grades.
 */
class EggProductionTriTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        // Contexte multi-ferme : indispensable pour que le trait BelongsToFarm
        // renseigne farm_id (collectes, mouvements de stock…).
        $farm = Farm::create(['name' => 'Ferme Test', 'code' => 'FT-001', 'is_active' => true]);
        session(['current_farm_id' => $farm->id]);

        // Rôle admin → bypass complet des Gates (cf. AppServiceProvider).
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            [
                'label'        => 'Administrateur',
                'display_name' => 'Administrateur',
                'permissions'  => ['L', 'C', 'M', 'S'],
            ]
        );
        $this->user = User::factory()->create(['role_id' => $admin->id]);

        DB::statement('PRAGMA foreign_keys = OFF');

        $employeeId = DB::table('employees')->insertGetId([
            'farm_id' => $farm->id, 'employee_id' => 'EMP-2026-001',
            'last_name' => 'Kante', 'first_name' => 'Moussa', 'gender' => 'M',
            'phone' => '622000000', 'job_title' => 'Chef de Ferme', 'department' => 'Production',
            'contract_type' => 'CDI', 'hire_date' => now()->subYear()->format('Y-m-d'),
            'status' => 'Actif', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $buildingId = DB::table('buildings')->insertGetId([
            'farm_id' => $farm->id, 'name' => 'Hangar A1', 'type' => 'ponte',
            'surface' => 200, 'capacity' => 5000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $providerId = DB::table('providers')->insertGetId([
            'farm_id' => $farm->id, 'name' => 'Elevage Guinee', 'phone' => '655112233',
            'type' => 'Poussins', 'provider_id' => 'FRN-2026-001', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['XL', 'L', 'M', 'S', 'Cassé', 'Anomalie'] as $g) {
            Stock::create([
                'item_name' => $g, 'category' => 'oeufs', 'unit' => 'Alvéole',
                'alert_threshold' => 10, 'current_quantity' => 0,
            ]);
        }

        $productionTypeId = \App\Models\ProductionType::resolveOrCreate('ponte', null)->id;

        $batchId = DB::table('batches')->insertGetId([
            'farm_id' => $farm->id, 'code' => 'B-2026-TEST', 'building_id' => $buildingId,
            'provider_id' => $providerId, 'employee_id' => $employeeId, 'production_type_id' => $productionTypeId,
            'model_name' => 'Lohmann Brown', 'initial_quantity' => 1000, 'current_quantity' => 1000,
            'qty_males' => 0, 'qty_females' => 1000, 'mating_ratio' => 0, 'qty_dead' => 0,
            'chick_state' => 'Excellent', 'production_phase' => 'ponte',
            'arrival_mortality_rate' => 0, 'avg_weight_start' => 0.040,
            'arrival_date' => now()->subMonths(3)->format('Y-m-d'),
            'start_date' => now()->subMonths(3)->format('Y-m-d'),
            'expected_end_date' => now()->addYear()->format('Y-m-d'),
            'buy_price_per_unit' => 5500, 'total_acquisition_cost' => 5500000, 'status' => 'Actif',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->batch = Batch::find($batchId);
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /** @test */
    public function test_tri_synchronise_le_stock_par_calibre(): void
    {
        $production = EggProduction::create([
            'farm_id' => $this->batch->farm_id, 'batch_id' => $this->batch->id,
            'production_date' => now()->format('Y-m-d'), 'total_eggs_collected' => 120,
            'is_graded' => false, 'grade_xl' => 0, 'grade_l' => 0, 'grade_m' => 0, 'grade_s' => 0,
            'broken_eggs' => 0, 'small_eggs' => 0, 'incubable_eggs' => 0, 'laying_rate' => 0,
        ]);

        $response = $this->actingAs($this->user)->put(
            route('egg-productions.update-tri', $production),
            [
                'grade_xl_alv' => 1, 'grade_xl_uni' => 0, // 30
                'grade_l_alv'  => 1, 'grade_l_uni'  => 0, // 30
                'grade_m_alv'  => 1, 'grade_m_uni'  => 0, // 30
                'grade_s_alv'  => 0, 'grade_s_uni'  => 0, // 0
                'broken_eggs'  => 30,                     // 30  → total 120
                'small_eggs'   => 0,
            ]
        );

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertEquals(1.0, (float) Stock::where('item_name', 'XL')->value('current_quantity'));
        $this->assertTrue((bool) $production->fresh()->is_graded);
    }

    /** @test */
    public function test_tri_refuse_un_total_incoherent(): void
    {
        $production = EggProduction::create([
            'farm_id' => $this->batch->farm_id, 'batch_id' => $this->batch->id,
            'production_date' => now()->format('Y-m-d'), 'total_eggs_collected' => 120,
            'is_graded' => false, 'laying_rate' => 0, 'incubable_eggs' => 0,
        ]);

        $this->actingAs($this->user)->put(
            route('egg-productions.update-tri', $production),
            ['grade_xl_alv' => 5, 'grade_xl_uni' => 0, 'broken_eggs' => 0, 'small_eggs' => 0] // 150 > 120
        )->assertSessionHasErrors('logic');
    }

    /** @test */
    public function test_le_parametre_egg_grades_pilote_les_calibres_actifs(): void
    {
        // Par défaut : les 4 calibres standard, dans l'ordre du paramètre.
        Setting::set('production.egg_grades', 'XL,L,M,S');
        $this->assertSame(['XL', 'L', 'M', 'S'], EggProduction::gradeCodes());

        // Sous-ensemble + réordonnancement réellement appliqués.
        Setting::set('production.egg_grades', 'S,M');
        $this->assertSame(['S', 'M'], EggProduction::gradeCodes());

        // Valeur erronée : ignorée sans casse, retombe sur le catalogue complet.
        Setting::set('production.egg_grades', 'foo,bar');
        $this->assertSame(['XL', 'L', 'M', 'S'], EggProduction::gradeCodes());
    }

    /** @test */
    public function test_collecte_inferieure_ou_egale_a_l_effectif_est_acceptee(): void
    {
        // 1000 sujets, 950 œufs = 95 % → valide
        $response = $this->actingAs($this->user)->post(route('egg-productions.store'), [
            'batch_id'             => $this->batch->id,
            'production_date'      => now()->format('Y-m-d'),
            'total_eggs_collected' => 950,
            'broken_eggs'          => 0,
            'small_eggs'           => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('egg_productions', [
            'batch_id'             => $this->batch->id,
            'total_eggs_collected' => 950,
        ]);
    }

    /** @test */
    public function test_collecte_superieure_a_l_effectif_est_bloquee(): void
    {
        // 1000 sujets, 1200 œufs = 120 % → biologiquement impossible
        $response = $this->actingAs($this->user)->post(route('egg-productions.store'), [
            'batch_id'             => $this->batch->id,
            'production_date'      => now()->format('Y-m-d'),
            'total_eggs_collected' => 1200,
            'broken_eggs'          => 0,
            'small_eggs'           => 0,
        ]);

        $response->assertSessionHasErrors('total_eggs_collected');
        $this->assertStringContainsString(
            '100 %',
            $response->getSession()->get('errors')->first('total_eggs_collected')
        );
        $this->assertDatabaseMissing('egg_productions', ['batch_id' => $this->batch->id]);
    }

    /** @test */
    public function test_cumul_journalier_bloque_quand_total_depasse_l_effectif(): void
    {
        // Premier passage : 900 œufs (90 %) → existant en base
        EggProduction::create([
            'farm_id'              => $this->batch->farm_id,
            'batch_id'             => $this->batch->id,
            'production_date'      => now()->format('Y-m-d'),
            'total_eggs_collected' => 900,
            'broken_eggs'          => 0,
            'small_eggs'           => 0,
            'is_graded'            => false,
            'laying_rate'          => 90,
        ]);

        // Deuxième passage : +200 → cumulé = 1100 > 1000 → bloqué
        $response = $this->actingAs($this->user)->post(route('egg-productions.store'), [
            'batch_id'             => $this->batch->id,
            'production_date'      => now()->format('Y-m-d'),
            'total_eggs_collected' => 200,
            'broken_eggs'          => 0,
            'small_eggs'           => 0,
        ]);

        $response->assertSessionHasErrors('total_eggs_collected');
        // La collecte existante ne doit pas avoir été modifiée
        $this->assertDatabaseHas('egg_productions', [
            'batch_id'             => $this->batch->id,
            'total_eggs_collected' => 900,
        ]);
    }

    /** @test */
    public function test_cumul_journalier_accepte_si_total_reste_dans_l_effectif(): void
    {
        // Premier passage : 700 œufs
        EggProduction::create([
            'farm_id'              => $this->batch->farm_id,
            'batch_id'             => $this->batch->id,
            'production_date'      => now()->format('Y-m-d'),
            'total_eggs_collected' => 700,
            'broken_eggs'          => 0,
            'small_eggs'           => 0,
            'is_graded'            => false,
            'laying_rate'          => 70,
        ]);

        // Deuxième passage : +200 → cumulé = 900 ≤ 1000 → accepté
        $response = $this->actingAs($this->user)->post(route('egg-productions.store'), [
            'batch_id'             => $this->batch->id,
            'production_date'      => now()->format('Y-m-d'),
            'total_eggs_collected' => 200,
            'broken_eggs'          => 0,
            'small_eggs'           => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('egg_productions', [
            'batch_id'             => $this->batch->id,
            'total_eggs_collected' => 900, // 700 + 200
        ]);
    }
}
