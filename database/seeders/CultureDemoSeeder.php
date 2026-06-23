<?php

namespace Database\Seeders;

use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropRecipe;
use App\Models\CropSpecies;
use App\Models\CropTransformation;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\WeatherReading;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Données de DÉMONSTRATION du module Production Végétale.
 *
 * Opt-in (NON appelé par DatabaseSeeder) — à lancer explicitement :
 *   php artisan db:seed --class=Database\\Seeders\\CultureDemoSeeder
 *
 * Idempotent par code de parcelle / cycle : relançable sans doublon.
 */
class CultureDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $farmId = Farm::defaultId();

        // ─── CATALOGUE DES CULTURES (référentiel guinéen) ───
        $this->seedCatalogue();

        // ─── CAMPAGNE AGRICOLE EN COURS ───
        $campaign = CropCampaign::updateOrCreate(
            ['code' => 'CAM-' . now()->year . '-GSP'],
            [
                'farm_id'             => $farmId,
                'name'                => 'Grande saison pluies ' . now()->year,
                'year'                => now()->year,
                'season'              => 'grande_saison_pluies',
                'start_date'          => now()->startOfYear()->addMonths(4)->toDateString(),
                'end_date_planned'    => now()->startOfYear()->addMonths(9)->toDateString(),
                'target_production_t' => 45,
                'status'              => CropCampaign::STATUS_EN_COURS,
            ]
        );

        // ─── PARCELLES ───
        $nord = Plot::updateOrCreate(
            ['code' => 'P-NORD'],
            [
                'farm_id'         => $farmId,
                'name'            => 'Parcelle Nord',
                'area_ha'         => 3.5,
                'location'        => 'Coyah, zone humide',
                'soil_type'       => 'Argilo-limoneux',
                'irrigation_type' => 'Goutte-à-goutte',
                'status'          => Plot::STATUS_EN_CULTURE,
            ]
        );

        $sud = Plot::updateOrCreate(
            ['code' => 'P-SUD'],
            [
                'farm_id'         => $farmId,
                'name'            => 'Parcelle Sud',
                'area_ha'         => 2.0,
                'location'        => 'Coyah, plateau',
                'soil_type'       => 'Sableux',
                'irrigation_type' => 'Pluvial',
                'status'          => Plot::STATUS_EN_CULTURE,
            ]
        );

        $est = Plot::updateOrCreate(
            ['code' => 'P-EST'],
            [
                'farm_id'         => $farmId,
                'name'            => 'Parcelle Est',
                'area_ha'         => 1.5,
                'location'        => 'Coyah, bas-fond',
                'soil_type'       => 'Hydromorphe',
                'irrigation_type' => 'Aspersion',
                'status'          => Plot::STATUS_DISPONIBLE,
            ]
        );

        // ─── CYCLE 1 : Maïs en cours (Parcelle Nord) ───
        $mais = CropCycle::updateOrCreate(
            ['code' => 'CY-MAIS-01'],
            [
                'farm_id'               => $farmId,
                'plot_id'               => $nord->id,
                'campaign_id'           => $campaign->id,
                'crop_name'             => 'Maïs',
                'variety'               => 'DK 818',
                'area_used_ha'          => 3.0,
                'planting_date'         => now()->subDays(50)->toDateString(),
                'expected_harvest_date' => now()->addDays(40)->toDateString(),
                'expected_yield_kg'     => 12000,
                'seed_quantity'         => 75,
                'seed_unit'             => 'kg',
                'status'                => CropCycle::STATUS_EN_COURS,
                'total_acquisition_cost' => 1_500_000,
            ]
        );

        $this->addInputs($mais, [
            ['type' => 'semence', 'name' => 'Semence maïs DK 818', 'quantity' => 75, 'unit' => 'kg', 'unit_cost' => 12000],
            ['type' => 'engrais', 'name' => 'NPK 15-15-15', 'quantity' => 300, 'unit' => 'kg', 'unit_cost' => 4500],
            ['type' => 'phyto', 'name' => 'Herbicide sélectif', 'quantity' => 10, 'unit' => 'litre', 'unit_cost' => 35000],
        ]);

        // ─── CYCLE 2 : Manioc en récolte (Parcelle Sud) ───
        $manioc = CropCycle::updateOrCreate(
            ['code' => 'CY-MANIOC-01'],
            [
                'farm_id'               => $farmId,
                'plot_id'               => $sud->id,
                'campaign_id'           => $campaign->id,
                'crop_name'             => 'Manioc',
                'variety'               => 'Locale améliorée',
                'area_used_ha'          => 2.0,
                'planting_date'         => now()->subDays(300)->toDateString(),
                'expected_harvest_date' => now()->subDays(10)->toDateString(),
                'expected_yield_kg'     => 30000,
                'status'                => CropCycle::STATUS_RECOLTE,
                'total_acquisition_cost' => 800_000,
                'additional_costs'      => 600_000,
                'total_revenue'         => 4_500_000,
            ]
        );

        $this->addHarvests($manioc, [
            ['days_ago' => 12, 'quantity' => 8000, 'quality' => 'bon'],
            ['days_ago' => 5,  'quantity' => 9500, 'quality' => 'bon'],
        ]);

        $this->addInputs($manioc, [
            ['type' => 'main_doeuvre', 'name' => 'Récolte manuelle', 'total_cost' => 450000],
        ]);

        // ─── CYCLE 3 : Tomate terminé (Parcelle Est, libérée) ───
        $tomate = CropCycle::updateOrCreate(
            ['code' => 'CY-TOMATE-01'],
            [
                'farm_id'               => $farmId,
                'plot_id'               => $est->id,
                'crop_name'             => 'Tomate',
                'variety'               => 'Mongal F1',
                'area_used_ha'          => 1.0,
                'planting_date'         => now()->subDays(120)->toDateString(),
                'closing_date'          => now()->subDays(10)->toDateString(),
                'status'                => CropCycle::STATUS_TERMINE,
                'total_acquisition_cost' => 1_200_000,
                'additional_costs'      => 900_000,
                'total_revenue'         => 6_800_000,
            ]
        );

        $this->addHarvests($tomate, [
            ['days_ago' => 40, 'quantity' => 1200, 'quality' => 'bon'],
            ['days_ago' => 30, 'quantity' => 1500, 'quality' => 'bon'],
            ['days_ago' => 20, 'quantity' => 1100, 'quality' => 'moyen'],
        ]);

        // ─── RECETTE : Gari de manioc ───
        $recipe = CropRecipe::updateOrCreate(
            ['code' => 'REC-GARI'],
            [
                'farm_id'                 => $farmId,
                'name'                    => 'Gari de manioc',
                'transformation_type'     => 'sechage',
                'output_product'          => 'Gari',
                'output_unit'             => 'kg',
                'expected_yield_percent'  => 30,
                'shelf_life_days'         => 180,
                'estimated_cost'          => 350_000,
                'is_active'               => true,
            ]
        );
        $recipe->items()->delete();
        $recipe->items()->createMany([
            ['input_product' => 'Manioc frais', 'quantity' => 5000, 'unit' => 'kg'],
            ['input_product' => 'Eau', 'quantity' => 200, 'unit' => 'litre'],
        ]);

        // ─── RELEVÉS MÉTÉO (mois en cours) ───
        $this->seedWeather($farmId, $nord->id);

        // ─── TRANSFORMATION : Manioc → Gari ───
        CropTransformation::updateOrCreate(
            ['batch_number' => 'TRV-DEMO-000001'],
            [
                'farm_id'             => $farmId,
                'crop_cycle_id'       => $manioc->id,
                'crop_recipe_id'      => $recipe->id,
                'input_product'       => 'Manioc',
                'output_product'      => 'Gari',
                'transformation_type' => 'sechage',
                'input_quantity'      => 5000,
                'input_unit'          => 'kg',
                'output_quantity'     => 1500,
                'output_unit'         => 'kg',
                'yield_percent'       => 30,
                'production_date'     => now()->subDays(3)->toDateString(),
                'expiry_date'         => now()->addMonths(6)->toDateString(),
                'production_cost'     => 350_000,
                'output_unit_price'   => 9000,
                'status'              => CropTransformation::STATUS_TERMINE,
            ]
        );

        $this->command?->info('✅ Démo Production Végétale : catalogue, 1 campagne, 3 parcelles, 3 cycles, récoltes, intrants, 1 recette, relevés météo et 1 transformation.');
    }

    /**
     * Catalogue agronomique de référence (espèces + variétés), idempotent par nom.
     */
    private function seedCatalogue(): void
    {
        $catalogue = [
            ['type' => 'cereale',    'name' => 'Maïs',   'local_name' => 'Mangban', 'family' => 'Poaceae',       'cycle_days_min' => 90,  'cycle_days_max' => 120, 'avg_yield_tha' => 4.0,
                'varieties' => [['name' => 'DK 818', 'cycle_days' => 100, 'avg_yield_tha' => 4.5], ['name' => 'Obatampa', 'cycle_days' => 110, 'avg_yield_tha' => 3.8]]],
            ['type' => 'cereale',    'name' => 'Riz',    'local_name' => 'Malo',    'family' => 'Poaceae',       'cycle_days_min' => 110, 'cycle_days_max' => 150, 'avg_yield_tha' => 3.5,
                'varieties' => [['name' => 'NERICA', 'cycle_days' => 120, 'avg_yield_tha' => 4.0]]],
            ['type' => 'tubercule',  'name' => 'Manioc', 'local_name' => 'Bantara', 'family' => 'Euphorbiaceae', 'cycle_days_min' => 270, 'cycle_days_max' => 365, 'avg_yield_tha' => 15.0,
                'varieties' => [['name' => 'Locale améliorée', 'cycle_days' => 300, 'avg_yield_tha' => 16.0]]],
            ['type' => 'tubercule',  'name' => 'Igname', 'local_name' => 'Khabi',   'family' => 'Dioscoreaceae', 'cycle_days_min' => 210, 'cycle_days_max' => 300, 'avg_yield_tha' => 12.0, 'varieties' => []],
            ['type' => 'maraicher',  'name' => 'Tomate', 'local_name' => null,      'family' => 'Solanaceae',    'cycle_days_min' => 90,  'cycle_days_max' => 120, 'avg_yield_tha' => 25.0,
                'varieties' => [['name' => 'Mongal F1', 'cycle_days' => 95, 'avg_yield_tha' => 30.0]]],
            ['type' => 'maraicher',  'name' => 'Oignon', 'local_name' => null,      'family' => 'Amaryllidaceae','cycle_days_min' => 90,  'cycle_days_max' => 130, 'avg_yield_tha' => 20.0, 'varieties' => []],
            ['type' => 'legumineuse','name' => 'Arachide','local_name' => 'Tiga',   'family' => 'Fabaceae',      'cycle_days_min' => 90,  'cycle_days_max' => 120, 'avg_yield_tha' => 1.5, 'varieties' => []],
            ['type' => 'fruitier',   'name' => 'Mangue', 'local_name' => null,      'family' => 'Anacardiaceae', 'cycle_days_min' => null,'cycle_days_max' => null,'avg_yield_tha' => 10.0, 'varieties' => [['name' => 'Kent', 'cycle_days' => null, 'avg_yield_tha' => 12.0]]],
        ];

        foreach ($catalogue as $row) {
            $varieties = $row['varieties'];
            unset($row['varieties']);

            $species = CropSpecies::updateOrCreate(['name' => $row['name']], $row);

            foreach ($varieties as $v) {
                $species->varieties()->updateOrCreate(['name' => $v['name']], $v);
            }
        }
    }

    /** Quelques relevés météo sur le mois en cours (idempotent par date). */
    private function seedWeather(?int $farmId, int $plotId): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $date = now()->subDays($i * 4);
            WeatherReading::updateOrCreate(
                ['farm_id' => $farmId, 'plot_id' => $plotId, 'reading_date' => $date->toDateString()],
                [
                    'temperature_min' => 22 + ($i % 3),
                    'temperature_max' => 30 + ($i % 4),
                    'humidity_pct'    => 75 + ($i % 10),
                    'rainfall_mm'     => $i % 2 === 0 ? 12.5 * $i : 0,
                    'wind_kmh'        => 8 + $i,
                    'sunshine_h'      => 6 + ($i % 4),
                ]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * Idempotent : on repart d'un journal de récoltes propre pour le cycle de
     * démo (un updateOrCreate sur harvest_date ne matcherait pas — colonne
     * `date` stockée en datetime), évitant les doublons en cas de relance.
     */
    private function addHarvests(CropCycle $cycle, array $rows): void
    {
        $cycle->harvests()->forceDelete();

        foreach ($rows as $r) {
            Harvest::create([
                'crop_cycle_id' => $cycle->id,
                'farm_id'       => $cycle->farm_id,
                'harvest_date'  => now()->subDays($r['days_ago'])->toDateString(),
                'quantity'      => $r['quantity'],
                'unit'          => 'kg',
                'quality'       => $r['quality'] ?? 'bon',
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function addInputs(CropCycle $cycle, array $rows): void
    {
        foreach ($rows as $r) {
            $quantity = (float) ($r['quantity'] ?? 0);
            $unitCost = (float) ($r['unit_cost'] ?? 0);
            $total    = $r['total_cost'] ?? round($quantity * $unitCost, 2);

            CropInput::updateOrCreate(
                ['crop_cycle_id' => $cycle->id, 'name' => $r['name']],
                [
                    'farm_id'    => $cycle->farm_id,
                    'type'       => $r['type'],
                    'quantity'   => $quantity,
                    'unit'       => $r['unit'] ?? 'kg',
                    'unit_cost'  => $unitCost,
                    'total_cost' => $total,
                    'input_date' => now()->subDays(45)->toDateString(),
                ]
            );
        }
    }
}
