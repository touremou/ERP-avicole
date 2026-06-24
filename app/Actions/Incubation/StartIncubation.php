<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use App\Models\Batch;
use App\Models\Incubator;
use App\Models\ProductionType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartIncubation
{
    public function execute(array $data): Incubation
    {
        return DB::transaction(function () use ($data) {
            $incubator = Incubator::findOrFail($data['incubator_id']);
            $batchId = $this->resolveBatchId($data);
            
            $duration = (int) ($data['duration'] ?? 21); // Espèce par défaut : Poule

            // Coût unitaire des œufs mis à couver : prix d'achat (œufs fournisseur)
            // ou valeur interne (œufs collectés). Repli sur un défaut paramétrable.
            $eggUnitCost = isset($data['egg_unit_cost']) && $data['egg_unit_cost'] !== ''
                ? (float) $data['egg_unit_cost']
                : (float) setting('couvoir.egg_unit_cost', 0);

            $incubation = Incubation::create([
                'batch_id'            => $batchId,
                'incubator_id'        => $incubator->id,
                'code_incubation'     => 'INC-' . now()->format('ymd') . '-' . strtoupper(Str::random(4)),
                'start_date'          => $data['start_date'],
                'incubation_duration' => $duration,
                'hatch_date_expected' => Carbon::parse($data['start_date'])->addDays($duration),
                'eggs_count'          => $data['eggs_count'],
                'egg_unit_cost'       => $eggUnitCost,
                'status'              => 'incubation'
            ]);

            $incubator->update(['status' => 'Occupé']);

            return $incubation;
        });
    }

    private function resolveBatchId(array $data): int
    {
        if ($data['source_type'] === 'internal') {
            return (int) $data['batch_id'];
        }

        // 1. Le bâtiment virtuel de transit
        $externalBuilding = \App\Models\Building::firstOrCreate(
            ['name' => 'Zone Fournisseurs Externes'],
            [
                'type'        => 'reproducteur',
                'surface'     => 1,
                'capacity'    => 999999,
                'description' => 'Bâtiment virtuel de transit pour le traçage.'
            ]
        );

        // 2. Traitement du fournisseur (Création ou Récupération)
        if ($data['provider_id'] === 'new') {
            $provider = \App\Models\Provider::create([
                'name'  => $data['new_provider_name'],
                'phone' => $data['new_provider_phone'],
                'type'  => $data['new_provider_type'],
            ]);
        } else {
            $provider = \App\Models\Provider::findOrFail($data['provider_id']);
        }

        // 3. Détermination de l'employé responsable (Traçabilité ERP)
        // Logique en cascade : on cherche l'employé lié au compte, 
        // sinon on utilise l'ID du compte, sinon le tout premier employé de l'usine.
        $employeeId = auth()->user()->employee_id 
                      ?? auth()->id() 
                      ?? \App\Models\Employee::first()->id 
                      ?? 1;

        /// 4. Création du lot externe rattaché (Blindage absolu)
        $batch = \App\Models\Batch::firstOrCreate(
            ['code' => 'EXT-' . strtoupper(\Illuminate\Support\Str::slug($provider->name))],
            [
                // --- 1. Identifiants et Base ---
                'production_type_id'    => ProductionType::resolveOrCreate('reproducteur', null)->id,
                'status'                 => 'Actif',
                'building_id'            => $externalBuilding->id,
                'provider_id'            => $provider->id,
                'employee_id'            => $employeeId,
                'description'            => "Achat externe : " . $provider->name,

                // --- 2. Planification ---
                'arrival_date'           => now(),
                'expected_end_date'      => now()->addDays(21),
                'production_phase'       => 'Attente/Incubation',
                
                // --- 3. Quantités (Tout à 0 car ce sont des oeufs, pas des volailles) ---
                'initial_quantity'       => 0,
                'current_quantity'       => 0,
                'qty_alive'              => 0,
                'qty_dead'               => 0,
                'qty_males'              => 0,
                'qty_females'            => 0,
                
                // --- 4. Variables Zootechniques (Initialisées à vide/zéro) ---
                'age_at_arrival'         => 0,
                'avg_weight_start'       => 0,
                'mating_ratio'           => 0,
                'chick_state'            => 'Normal', // Valeur standard attendue par ton Enum/Validation
                'vaccination_received'   => false,
                'planned_density'        => 0,
                'arrival_mortality_rate' => 0,
                
                // --- 5. Variables Financières ---
                'buy_price_per_unit'     => 0,
                'total_acquisition_cost' => 0,
                'additional_costs'       => 0,
                
                // --- 6. Attributs Système ---
                'is_synced'              => 0,
            ]
        );

        return $batch->id;
    }
}

/*

namespace App\Actions\Incubation;

use App\Models\Incubation;
use App\Models\Batch;
use App\Models\Incubator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartIncubation
{
    public function execute(array $data): Incubation
    {
        return DB::transaction(function () use ($data) {
            $incubator = Incubator::findOrFail($data['incubator_id']);
            
            // 1. Résolution de la source (Lot interne vs Achat externe)
            $batchId = $this->resolveBatchId($data);

            // 2. [INTÉGRATION STOCK] : Décrémentation du magasin d'œufs
            // Si la source est interne, les œufs doivent sortir du stock "Œufs à couver".
            // if ($data['source_type'] === 'internal') {
            //     app(StockIntegrationService::class)->deductIncubableEggs($data['eggs_count']);
            // }
            // Dans StartIncubation.php
            $duration = $data['duration'] ?? 21; // Préparation pour l'avenir

            // 3. Création du cycle
            $incubation = Incubation::create([
                'batch_id'            => $batchId,
                'incubator_id'        => $incubator->id,
                'code_incubation'     => 'INC-' . now()->format('ymd') . '-' . strtoupper(Str::random(4)),
                'start_date'          => $data['start_date'],
                'hatch_date_expected' => Carbon::parse($data['start_date'])->addDays($duration),
                'eggs_count'          => $data['eggs_count'],
                'status'              => 'incubation'
            ]);

            // 4. Verrouillage de la machine
            $incubator->update(['status' => 'Occupé']);

            return $incubation;
        });
    }

    private function resolveBatchId(array $data): int
    {
        if ($data['source_type'] === 'internal') {
            return (int) $data['batch_id'];
        }

        // Création d'un lot fantôme de traçabilité pour les œufs externes
        $batch = Batch::firstOrCreate(
            ['code' => 'EXT-' . strtoupper(Str::slug($data['external_source_name']))],
            [
                'type' => 'reproducteur',
                'status' => 'Actif',
                'description' => "Achat externe d'œufs à couver : " . $data['external_source_name']
            ]
        );

        return $batch->id;
    }
}
*/