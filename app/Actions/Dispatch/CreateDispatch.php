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

            // ─── 1. CRÉER L'EXPÉDITION ───
            // Numérotation par le service central : elle se faisait ici à la
            // main, donc sans le verrou qui sérialise deux demandes simultanées,
            // et hors de la garde qui exige un index unique par colonne
            // numérotée — garde qui dérive des schémas déclarés au service.
            $dispatch = Dispatch::create([
                'dispatch_number'      => \App\Services\DocumentNumberingService::generate('dispatch'),
                'sale_id'              => $data['sale_id'] ?? null,
                'dispatched_by'        => Auth::id(),
                'intended_receiver_id' => $data['intended_receiver_id'] ?? null,
                'vehicle_plate'        => $data['vehicle_plate'] ?? null,
                'driver_name'          => $data['driver_name'],
                'driver_phone'         => $data['driver_phone'] ?? null,
                'dispatch_date'        => $data['dispatch_date'],
                'dispatch_time'        => $data['dispatch_time'] ?? null,
                'destination'          => $data['destination'],
                'status'               => 'expedie',
                'notes'                => $data['notes'] ?? null,
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

                /*
                 * UNE EXPÉDITION NE RETIRE PAS CE QUE SA VENTE A DÉJÀ RETIRÉ.
                 *
                 * `ValidateSale` déstocke à la validation — articles de magasin
                 * ET effectif du lot pour les animaux vifs. Cette action faisait
                 * exactement la même chose, sans regarder si la vente était
                 * passée par là. Résultat mesuré : 100 sujets vendus puis
                 * expédiés en retiraient 200 du lot, et 50 articles vendus en
                 * retiraient 100 du magasin.
                 *
                 * #305 a rendu le cas courant : une vente encaissée se valide
                 * désormais d'office, donc tout bon de livraison émis derrière
                 * décomptait une seconde fois.
                 *
                 * Une expédition SANS vente — ou dont la vente est encore un
                 * brouillon, donc jamais déstockée — doit continuer de déstocker :
                 * la marchandise quitte bien la ferme. C'est le seul cas où ce
                 * geste est le fait générateur.
                 */
                if (! $this->saleAlreadyDestocked($dispatch)) {
                    $this->destockAtFarm($dispatchItem);
                }
            }

            Log::info("Expédition {$dispatch->dispatch_number} créée — {$dispatch->destination} — Chauffeur: {$dispatch->driver_name}");

            // Notifier le récepteur désigné qu'une expédition l'attend.
            if ($dispatch->intended_receiver_id) {
                rescue(fn () => app(\App\Services\NotificationHub::class)
                    ->notifyDispatchReceiver($dispatch->fresh('intendedReceiver')));
            }

            return $dispatch->fresh('items');
        });
    }

    /**
     * La vente de cette expédition a-t-elle déjà sorti la marchandise ?
     *
     * `valide` et `livre` ne sont posés que par `ValidateSale`, qui déstocke
     * dans la même transaction : ces deux statuts prouvent donc que la sortie a
     * eu lieu. Un `brouillon` n'a rien déstocké, une vente `annule` a été
     * restockée par `CancelSale` — dans les deux cas l'expédition reste le fait
     * générateur.
     */
    private function saleAlreadyDestocked(Dispatch $dispatch): bool
    {
        return $dispatch->sale_id
            && in_array($dispatch->sale?->status, ['valide', 'livre'], true);
    }

    private function destockAtFarm(DispatchItem $item): void
    {
        // Articles stockés (œufs, lait, aliment, produits_finis, matériel)
        if ($item->requiresDestock()) {
            $result = StockIntegrationService::syncMovement(
                $item->product_name,
                Stock::categoryForProductType($item->product_type),
                (float) $item->quantity_dispatched,
                'out',
                "Expédition {$item->dispatch->dispatch_number} → {$item->dispatch->destination}",
                match ($item->unit) {
                    'alveole' => 'Alvéole',
                    'sac'     => 'Sac',
                    'litre'   => 'Litre',
                    'tete'    => 'Tête',
                    default   => 'KG',
                }
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
