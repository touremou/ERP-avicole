<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Stock;
use App\Models\User;
use App\Models\EggProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EggProductionTriTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $batch;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $this->user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@avismart.test',
            'password' => bcrypt('password'),
            'role' => 'admin'
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'employee_id' => 'EMP-2026-001',
            'last_name' => 'Kante',
            'first_name' => 'Moussa',
            'gender' => 'M',
            'phone' => '622000000',
            'email' => 'moussa@avismart.test',
            'job_title' => 'Chef de Ferme',
            'department' => 'Production',
            'contract_type' => 'CDI',
            'hire_date' => now()->subYear()->format('Y-m-d'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $buildingId = DB::table('buildings')->insertGetId([
            'name' => 'Hangar A1', 'type' => 'ponte', 'surface' => 200, 'capacity' => 5000,
            'created_at' => now(), 'updated_at' => now()
        ]);

        $providerId = DB::table('providers')->insertGetId([
            'name' => 'Elevage Guinee', 'phone' => '655112233', 'type' => 'Poussins', 'provider_id' => 1,
            'created_at' => now(), 'updated_at' => now()
        ]);

        $grades = ['XL', 'L', 'M', 'S', 'Cassé', 'Anomalie'];
        foreach ($grades as $g) {
            Stock::create([
                'item_name' => $g, 'category' => 'oeufs', 'unit' => 'Alvéole',
                'alert_threshold' => 10, 'current_quantity' => 0
            ]);
        }

        $batchId = DB::table('batches')->insertGetId([
            'code' => 'B-2026-TEST',
            'building_id' => $buildingId,
            'provider_id' => $providerId,
            'employee_id' => $employeeId,
            'responsible' => 'Moussa Kante',
            'type' => 'ponte',
            'model_name' => 'Lohmann Brown',
            'initial_quantity' => 1000,
            'current_quantity' => 1000,
            'qty_alive' => 1000,
            'qty_males' => 0,
            'qty_females' => 1000,
            'mating_ratio' => 0,
            'qty_dead' => 0,
            'chick_state' => 'Excellent',
            'arrival_date' => now()->subMonths(3)->format('Y-m-d'),
            'start_date' => now()->subMonths(3)->format('Y-m-d'),
            'expected_end_date' => now()->addYear()->format('Y-m-d'),
            'buy_price_per_unit' => 5500,
            'total_acquisition_cost' => 5500000,
            'status' => 'Actif',
            'production_phase' => 'ponte',
            'arrival_mortality_rate' => 0,
            'avg_weight_start' => 0.040,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->batch = Batch::find($batchId);
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /** @test */
    /** @test */
    public function test_tri_et_synchronisation_delta_stock()
    {
        $this->withoutMiddleware(); 

        $production = EggProduction::create([
            'batch_id' => $this->batch->id,
            'production_date' => now()->format('Y-m-d'),
            'total_eggs_collected' => 120,
            'is_graded' => false,
            'grade_xl' => 0, 'grade_l' => 0, 'grade_m' => 0, 'grade_s' => 0,
            'broken_eggs' => 0, 'small_eggs' => 0, 'incubable_eggs' => 0, 'laying_rate' => 0
        ]);

        // FORCE l'URL relative pour éviter le conflit avec WAMP (/avismart/public)
        // La route est : production/{eggProduction}/tri
        $url = "/production/{$production->id}/tri";

        $response = $this->actingAs($this->user)->put($url, [
            'grade_xl_alv' => 1, 'grade_xl_uni' => 0, // 30
            'grade_l_alv'  => 1, 'grade_l_uni'  => 0, // 30
            'grade_m_alv'  => 1, 'grade_m_uni'  => 0, // 30
            'grade_s_alv'  => 0, 'grade_s_uni'  => 0, // 0
            'broken_eggs'  => 30,                     // 30
            'small_eggs'   => 0,                      // Total = 120
        ]);

        // Si le 404 persiste, on dump pour debug
        if ($response->status() == 404) {
            dump("URL tentée : " . $url);
        }

        $response->assertStatus(302); 
        
        $stockXL = Stock::where('item_name', 'XL')->first();
        $this->assertEquals(1.0, (float)$stockXL->current_quantity);
    }

    /** @test */
    public function test_interdire_tri_si_total_incorrect()
    {
        $this->withoutMiddleware();

        $production = EggProduction::create([
            'batch_id' => $this->batch->id,
            'production_date' => now()->format('Y-m-d'),
            'total_eggs_collected' => 120,
            'laying_rate' => 0, 'incubable_eggs' => 0
        ]);

        $url = "/production/{$production->id}/tri";

        $response = $this->actingAs($this->user)->put($url, [
            'grade_xl_alv' => 5, 'grade_xl_uni' => 0, // 150 > 120
            'broken_eggs' => 0, 'small_eggs' => 0,
        ]);

        $response->assertSessionHasErrors('logic');
    }
}