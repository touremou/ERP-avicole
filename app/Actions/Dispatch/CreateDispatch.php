<?php

namespace App\Actions\Dispatch;

use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\Stock;
use App\Models\Batch;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CreateDispatch
{
    /**
     * Crée une expédition et DÉSTOCKE immédiatement de la ferme.
     *
     * Le déstockage se fait à l'expédition (pas à la vente) car la marchandise
     * QUITTE PHYSIQUEMENT la ferme. Le stock ferme diminue, le stock magasin
     * augmentera à la réception.
     */
    public function execute(array $data): Dispatch
    {
        return DB::transaction(function () use ($data) {

            // ─── 1. NUMÉROTATION ───
            $year = now()->format('Y');
            $lastNum = Dispatch::where('dispatch_number', 'LIKE', "EXP-{$year}-%")
                ->withTrashed()->max('dispatch_number');
            $seq = $lastNum ? (int) substr($lastNum, -6) + 1 : 1;

            // ─── 2. CRÉER L'EXPÉDITION ───
            $dispatch = Dispatch::create([
                'dispatch_number' => sprintf('EXP-%s-%06d', $year, $seq),
                'sale_id'         => $data['sale_id'] ?? null,
                'dispatched_by'   => Auth::id(),
                'vehicle_plate'   => $data['vehicle_plate'] ?? null,
                'driver_name'     => $data['driver_name'],
                'driver_phone'    => $data['driver_phone'] ?? null,
                'dispatch_date'   => $data['dispatch_date'],
                'dispatch_time'   => $data['dispatch_time'] ?? null,
                'destination'     => $data['destination'],
                'status'          => 'expedie',
                'notes'           => $data['notes'] ?? null,
            ]);

            // ─── 3. CRÉER LES LIGNES ET DÉSTOCKER ───
            foreach ($data['items'] as $item) {
                $dispatchItem = DispatchItem::create([
                    'dispatch_id'          => $dispatch->id,
                    'product_type'         => $item['product_type'],
                    'product_name'         => $item['product_name'],
                    'product_id'           => $item['product_id'] ?? null,
                    'batch_id'             => $item['batch_id'] ?? null,
                    'quantity_dispatched'  => $item['quantity'],
                    'unit'                 => $item['unit'],
                    'condition_at_dispatch' => $item['condition'] ?? 'bon',
                ]);

                // Déstockage ferme
                $this->destockAtFarm($dispatchItem);
            }

            Log::info("Expédition {$dispatch->dispatch_number} créée — {$dispatch->destination} — Chauffeur: {$dispatch->driver_name}");

            return $dispatch->fresh('items');
        });
    }

    private function destockAtFarm(DispatchItem $item): void
    {
        // Articles stockés (œufs, aliment, matériel)
        if ($item->requiresDestock()) {
            $category = match ($item->product_type) {
                'oeufs'   => 'oeufs',
                'aliment' => 'conso',
                default   => 'materiels',
            };

            $result = StockIntegrationService::syncMovement(
                $item->product_name,
                $category,
                (float) $item->quantity_dispatched,
                'out',
                "Expédition {$item->dispatch->dispatch_number} → {$item->dispatch->destination}",
                $item->unit === 'alveole' ? 'Alvéole' : ($item->unit === 'sac' ? 'Sac' : 'KG')
            );

            if (! $result) {
                throw new Exception("Stock insuffisant ou introuvable pour '{$item->product_name}'.");
            }
        }

        // Animal vif expédié à la tête → décrémenter l'effectif du lot (toute
        // espèce). Les expéditions au poids (carcasse au kg) ne décrémentent
        // pas l'effectif (le poids ne dit pas le nombre de têtes).
        if ($item->decrementsBatchCount()) {
            $batch = Batch::findOrFail($item->batch_id);
            $qty = (int) $item->quantity_dispatched;

            if ($batch->current_quantity < $qty) {
                throw new Exception("Effectif insuffisant dans le lot {$batch->code} : besoin {$qty}, disponible {$batch->current_quantity}.");
            }

            $batch->decrement('current_quantity', $qty);
        }
    }
}
