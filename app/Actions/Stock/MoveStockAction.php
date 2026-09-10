<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;

class MoveStockAction
{
    public function execute(int $stockId, string $type, float $quantityInput, ?string $notes, int $userId, ?string $uuid = null): void
    {
        DB::transaction(function () use ($stockId, $type, $quantityInput, $notes, $userId, $uuid) {
            $stock = Stock::lockForUpdate()->find($stockId);
            $oldQuantity = (float) $stock->current_quantity;
            $wasLow = $stock->is_low;
            $movQty = $quantityInput;
            $finalNotes = $notes ?? "Mouvement de stock manuel";

            if ($type === 'in') {
                $stock->increment('current_quantity', $quantityInput);
            } elseif ($type === 'out') {
                /*
                 * ON NE SORT PAS PLUS QUE CE QU'IL Y A — ET C'EST ICI QUE ÇA SE
                 * TRANCHE.
                 *
                 * La règle était énoncée par les TROIS appelants et par aucun
                 * écrivain : `MoveStockRequest::withValidator()`,
                 * `EggMovementController::storeMovement()` — dont le commentaire
                 * dit lui-même « Disponibilité, miroir de MoveStockRequest » — et
                 * `SyncService::stockMovementCreate()`. Le `decrement` ci-dessous
                 * n'avait, lui, aucune borne : une sortie de 500 sur un magasin
                 * de 100 laissait le stock à −400, avec un mouvement écrit pour
                 * l'expliquer. Aucun autre chemin de l'ERP ne produit de stock
                 * négatif.
                 *
                 * Et les trois gardes lisent la disponibilité HORS verrou : le
                 * verrou n'est pris qu'ici, deux lignes plus haut. Deux sorties
                 * concurrentes passaient donc toutes les deux la validation, et
                 * décrémentaient toutes les deux.
                 *
                 * C'est la leçon que `StockIntegrationService` a déjà tirée en
                 * toutes lettres : « la sortie est contrôlée SOUS le verrou pris
                 * ci-dessus. Sans lui, le plafonnement à zéro faisait
                 * "disparaître" silencieusement la matière manquante ».
                 *
                 * On REFUSE plutôt que de plafonner à zéro, pour la même raison :
                 * plafonner ferait disparaître la matière manquante sans trace.
                 * Les appelants gardent leurs vérifications — elles rendent un
                 * message au bon champ, avant d'en arriver là ; celle-ci est le
                 * dernier mot, pas le premier.
                 */
                if ($quantityInput > $oldQuantity + 0.0001) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'quantity' => "Stock insuffisant pour « {$stock->item_name} » : "
                            . number_format($oldQuantity, 1) . " {$stock->unit} disponibles, "
                            . number_format($quantityInput, 1) . " {$stock->unit} demandés.",
                    ]);
                }

                $stock->decrement('current_quantity', $quantityInput);
            } else {
                $stock->update(['current_quantity' => $quantityInput]);
                $movQty = abs($quantityInput - $oldQuantity);
                $finalNotes = ($notes ?? "Ajustement") . " (Précédent: {$oldQuantity} -> Nouveau: {$quantityInput})";
            }

            if ($type !== 'adjustment' || $movQty > 0) {
                StockMovement::create([
                    'uuid'     => $uuid,
                    'stock_id' => $stock->id,
                    'user_id'  => $userId,
                    'type'     => $type,
                    'quantity' => $movQty,
                    'notes'    => $finalNotes,
                ]);
            }

            // ─── ALERTES ───
            $stock->refresh();
            $hub = app(NotificationHub::class);

            // Ajustement manuel d'inventaire : vecteur de dissimulation de vol.
            if ($type === 'adjustment' && $movQty > 0) {
                $hub->alertStockAdjustment($stock, $oldQuantity, (float) $stock->current_quantity, $notes);
            }

            // Franchissement du seuil d'alerte (toute baisse manuelle).
            // `is_low` porte désormais la garde « seuil > 0 » (cf. Stock::getIsLowAttribute).
            if (! $wasLow && $stock->is_low) {
                $hub->alertStockCritical($stock);
            }
        });
    }
}