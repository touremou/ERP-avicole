<?php
namespace App\Services;

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BatchService
{
    /**
     * Logique de création/mise à jour universelle
     * Utilisée par le Web et par la Synchro Offline
     */
    public function updateOrCreateBatch(array $data)
    {
        return DB::transaction(function () use ($data) {
            $arrivalDate = Carbon::parse($data['arrival_date']);
            
            // Calculs financiers et techniques
            $qtyAlive = (int)($data['qty_alive'] ?? $data['current_quantity'] ?? 0);
            $qtyDead = (int)($data['qty_dead'] ?? 0);
            $totalArrivage = $qtyAlive + $qtyDead;
            $price = (float)$data['buy_price_per_unit'];

            $batch = Batch::updateOrCreate(
                ['uuid' => $data['uuid'] ?? (string) \Illuminate\Support\Str::uuid()],
                [
                    'code' => $data['code'],
                    'type' => $data['type'],
                    'building_id' => $data['building_id'],
                    'employee_id' => $data['employee_id'],
                    'provider_id' => $data['provider_id'],
                    'protocol_id' => $data['protocol_id'] ?? null,
                    'current_protocol_id' => $data['protocol_id'] ?? null,
                    'allocated_surface' => $data['allocated_surface'] ?? null,
                    'model_name' => $data['model_name'],
                    'initial_quantity' => $totalArrivage,
                    'current_quantity' => $qtyAlive,
                    'qty_alive' => $qtyAlive,
                    'qty_dead' => $qtyDead,
                    'arrival_date' => $arrivalDate,
                    'start_date' => $arrivalDate,
                    'buy_price_per_unit' => $price,
                    'total_acquisition_cost' => $totalArrivage * $price,
                    'status' => $data['status'] ?? 'Actif',
                    'production_phase' => 'demarrage',
                    'expected_end_date' => $arrivalDate->copy()->addDays($data['type'] === 'chair' ? 45 : 540),
                    'is_synced' => true,
                    'last_sync_at' => now(),
                ]
            );

            // LOGIQUE D'ALERTE INDUSTRIELLE
            if ($batch->arrival_mortality_rate > 3.0) {
                $manager = User::where('role', 'admin')->first();
                $manager->notify(new ProductionAlert([
                    'priority' => 'high',
                    'title' => 'Mortalité Transport Critique',
                    'message' => "Le lot {$batch->code} présente un taux de mortalité de {$batch->arrival_mortality_rate}%",
                    'batch_uuid' => $batch->uuid
                ]));
            }

            // Mise à jour du statut bâtiment
            Building::where('id', $data['building_id'])->update(['status' => Building::STATUS_OCCUPE]);

            return $batch;
        });
    }
}