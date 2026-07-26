<?php

namespace App\Actions\Stock;

use App\Models\StoredLot;
use App\Models\StoredLotCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre le CONTRÔLE PÉRIODIQUE d'un lot en conservation (T2).
 *
 * Source unique partagée par le web et la sync mobile — le contrôle se fait au
 * magasin, souvent sans réseau.
 *
 * Trois règles portées ici, et pas dans les contrôleurs :
 *
 *  1. LA FREINTE SE DÉDUIT D'UNE PESÉE, elle ne se saisit pas. On enregistre ce
 *     qu'on mesure ; l'écart est calculé. Saisir directement une perte
 *     laisserait passer un chiffre « au sentiment », impossible à recouper.
 *  2. LA FREINTE PASSE PAR UN AJUSTEMENT DE STOCK FORMEL (CreateStockAdjustment,
 *     motif « freinte »). Corriger la quantité du lot sans toucher l'inventaire
 *     ferait diverger les deux : le lot dirait 85 kg et le magasin 100.
 *  3. UN CONSTAT GRAVE EXIGE UNE DÉCISION. Insectes, moisissure ou marchandise
 *     dégradée avec « aucune action » est refusé : c'est précisément le contrôle
 *     qui sert d'alibi, celui qu'on cocherait pour être en règle.
 */
class RecordStoredLotCheck
{
    /**
     * @param array{
     *   checked_at?: string, weighed_quantity?: numeric|null, condition?: string,
     *   action_taken?: string, market_price?: numeric|null, employee_id?: int|null,
     *   photo_path?: string|null, notes?: string|null
     * } $data
     */
    public function execute(StoredLot $lot, array $data, ?int $userId = null): StoredLotCheck
    {
        $condition = $data['condition'] ?? 'bon';
        $action    = $data['action_taken'] ?? 'aucune';

        if (in_array($condition, StoredLotCheck::CONDITIONS_REQUIRING_ACTION, true) && $action === 'aucune') {
            throw ValidationException::withMessages([
                'action_taken' => sprintf(
                    'Constat « %s » : une décision est obligatoire (séchage, traitement, déclassement ou destruction). '
                    . 'Un contrôle qui constate un problème sans rien décider ne protège rien.',
                    StoredLotCheck::CONDITIONS[$condition] ?? $condition,
                ),
            ]);
        }

        return DB::transaction(function () use ($lot, $data, $userId, $condition, $action) {
            $before = (float) $lot->quantity_current;

            // ─── Freinte : dérivée de la pesée, jamais saisie ───
            $weighed = isset($data['weighed_quantity']) && $data['weighed_quantity'] !== null && $data['weighed_quantity'] !== ''
                ? round((float) $data['weighed_quantity'], 3)
                : null;

            if ($weighed !== null && $weighed > $before + 0.0001) {
                // Un lot conservé ne grossit pas. Une pesée supérieure est une
                // erreur de saisie ou un mélange avec un autre lot — dans les deux
                // cas, l'accepter fabriquerait de la matière.
                throw ValidationException::withMessages([
                    'weighed_quantity' => sprintf(
                        'Pesée (%s) supérieure au stock du lot (%s %s) : un lot conservé ne peut pas prendre du poids. '
                        . 'Vérifiez la balance, ou s\'il n\'y a pas eu mélange avec un autre lot.',
                        number_format($weighed, 1, ',', ' '),
                        number_format($before, 1, ',', ' '),
                        $lot->unit,
                    ),
                ]);
            }

            // Destruction : tout le reste est perdu, quelle que soit la pesée.
            $finalQuantity = $action === 'destruction' ? 0.0 : ($weighed ?? $before);
            $shrinkage = round($before - $finalQuantity, 3);

            $check = StoredLotCheck::create([
                'stored_lot_id'      => $lot->id,
                'farm_id'            => $lot->farm_id,
                'employee_id'        => $data['employee_id'] ?? null,
                'recorded_by'        => $userId,
                'checked_at'         => $data['checked_at'] ?? now(),
                'weighed_quantity'   => $weighed,
                'shrinkage_quantity' => max(0, $shrinkage),
                'condition'          => $condition,
                'action_taken'       => $action,
                'market_price'       => $data['market_price'] ?? null,
                'photo_path'         => $data['photo_path'] ?? null,
                'notes'              => $data['notes'] ?? null,
            ]);

            // ─── Répercussion sur l'INVENTAIRE (et non sur le seul lot) ───
            if ($shrinkage > 0.0001 && $lot->stock) {
                $stockAfter = max(0, round((float) $lot->stock->current_quantity - $shrinkage, 3));

                app(CreateStockAdjustment::class)->execute(
                    stockId: $lot->stock_id,
                    countedQuantity: $stockAfter,
                    reason: 'freinte',
                    notes: sprintf(
                        'Contrôle de conservation « %s » — %s',
                        $lot->label,
                        StoredLotCheck::CONDITIONS[$condition] ?? $condition,
                    ),
                    userId: $userId ?? 0,
                );
            }

            $lot->fill([
                'quantity_current' => $finalQuantity,
                // Dernier cours constaté : ne l'écrase que si un cours a été
                // relevé, pour ne pas effacer une observation par un contrôle
                // fait un jour de marché fermé.
                'last_market_price' => $data['market_price'] ?? $lot->last_market_price,
            ]);

            if ($action === 'destruction' || $finalQuantity <= 0) {
                $lot->fill([
                    'status'        => StoredLot::STATUS_PERTE,
                    'closed_at'     => now()->toDateString(),
                    'closed_reason' => $action === 'destruction'
                        ? 'Destruction décidée au contrôle du ' . now()->format('d/m/Y')
                        : 'Quantité épuisée par la freinte',
                ]);
            }

            $lot->save();

            return $check->fresh();
        });
    }
}
