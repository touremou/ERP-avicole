<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\Stock;
use App\Models\Batch;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ValidateSale
{
    /**
     * Valide une vente : effectue le déstockage et met à jour les compteurs.
     *
     * Le déstockage ne se fait QU'À LA VALIDATION, pas à la création (brouillon).
     * Ça permet de modifier/annuler un brouillon sans impacter les stocks.
     */
    public function execute(Sale $sale): Sale
    {
        if ($sale->status !== 'brouillon') {
            throw new Exception("La vente {$sale->reference} est déjà validée (statut: {$sale->status}).");
        }

        return DB::transaction(function () use ($sale) {

            // ─── 1. VÉRIFIER ET DÉSTOCKER CHAQUE LIGNE ───
            foreach ($sale->items as $item) {

                // Articles stockés (œufs, aliment, matériel)
                if ($item->requiresDestock()) {
                    $this->destockItem($item);
                }

                // Animal vif vendu à la tête → décrémenter l'effectif du lot
                if ($item->decrementsBatchCount()) {
                    $this->destockBatch($item);
                }

                // Lait, fumier, "autre", ventes au poids : pas de déstockage physique
            }

            // ─── 2. MARQUER COMME VALIDÉ ───
            $sale->update([
                'status'       => 'valide',
                'validated_at' => now(),
            ]);

            // ─── 3. METTRE À JOUR LE SOLDE CLIENT ───
            $sale->client->recalculateBalance();

            Log::info("Vente validée : {$sale->reference} — Déstockage effectué.");

            return $sale->fresh();
        });
    }

    /**
     * Déstocke un article du stock (œufs, aliment, matériel).
     */
    private function destockItem($item): void
    {
        if (! $item->product_id) {
            Log::warning("ValidateSale: ligne #{$item->id} sans product_id, déstockage par nom.");
        }

        $stock = $item->product_id
            ? Stock::find($item->product_id)
            : Stock::where('item_name', $item->product_name)->first();

        if (! $stock) {
            throw new Exception("Stock introuvable pour '{$item->product_name}'. Impossible de valider.");
        }

        if ((float) $stock->current_quantity < (float) $item->quantity) {
            throw new Exception(
                "Stock insuffisant pour '{$item->product_name}' : " .
                "besoin {$item->quantity} {$item->unit}, disponible {$stock->current_quantity} {$stock->unit}."
            );
        }

        // Utiliser StockIntegrationService pour la traçabilité
        $category = match ($item->product_type) {
            'oeufs'   => 'oeufs',
            'aliment' => 'conso',
            default   => 'materiels',
        };

        StockIntegrationService::syncMovement(
            $item->product_name,
            $category,
            (float) $item->quantity,
            'out',
            "Vente {$item->sale->reference} — Client: {$item->sale->client->name}",
            $item->unit === 'alveole' ? 'Alvéole' : ($item->unit === 'sac' ? 'Sac' : 'KG')
        );
    }

    /**
     * Décrémente l'effectif d'un lot (animal vif vendu à la tête, toute espèce).
     */
    private function destockBatch($item): void
    {
        $batch = Batch::find($item->batch_id);

        if (! $batch) {
            throw new Exception("Lot introuvable (id={$item->batch_id}) pour la ligne '{$item->product_name}'.");
        }

        if ($batch->status !== 'Actif') {
            throw new Exception("Le lot {$batch->code} n'est pas actif (statut: {$batch->status}).");
        }

        $qty = (int) $item->quantity;
        if ($batch->current_quantity < $qty) {
            throw new Exception(
                "Effectif insuffisant dans le lot {$batch->code} : " .
                "besoin {$qty}, disponible {$batch->current_quantity}."
            );
        }

        $batch->decrement('current_quantity', $qty);

        Log::info("Vente {$item->sale->reference} : {$qty} sujets vendus du lot {$batch->code}.");
    }
}
