<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\Stock;
use App\Models\Batch;
use App\Services\NotificationHub;
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

                // Articles stockés (œufs, lait, aliment, produits_finis, matériel)
                if ($item->requiresDestock()) {
                    $this->destockItem($item);
                }

                // Animal vif vendu à la tête → décrémenter l'effectif du lot
                if ($item->decrementsBatchCount()) {
                    $this->destockBatch($item);
                }

                // Fumier, "autre", ventes au poids (carcasse) : pas de déstockage physique
            }

            // ─── 2. MARQUER COMME VALIDÉ ───
            $sale->update([
                'status'       => 'valide',
                'validated_at' => now(),
            ]);

            // ─── 3. METTRE À JOUR LE SOLDE CLIENT ───
            $sale->client->recalculateBalance();

            Log::info("Vente validée : {$sale->reference} — Déstockage effectué.");

            // Visibilité admin/propriétaire (hors site) sur chaque vente validée
            app(NotificationHub::class)->notifySaleCreated($sale->fresh(['client']));

            return $sale->fresh();
        });
    }

    /**
     * Déstocke un article du stock (œufs, aliment, matériel).
     */
    private function destockItem($item): void
    {
        // AUDIT C1 (prouvé par drill parallèle) : sans verrou, deux validations
        // simultanées du même dernier stock passaient TOUTES LES DEUX le
        // contrôle ci-dessous (sur-vente silencieuse). lockForUpdate sérialise
        // le contrôle de disponibilité — la transaction de execute() l'englobe.
        $stock = $item->product_id
            ? Stock::lockForUpdate()->find($item->product_id)
            : Stock::where('item_name', $item->product_name)->lockForUpdate()->first();

        if (! $stock) {
            // product_id explicite mais stock disparu → vraie anomalie (FK), on bloque.
            if ($item->product_id) {
                throw new Exception("Stock introuvable pour '{$item->product_name}'. Impossible de valider.");
            }

            // Aucun stock cible (article catalogue NON suivi en stock, ou ligne en
            // saisie libre sans article de stock) : la vente est permise, on ne
            // décrémente simplement aucun stock.
            Log::warning("ValidateSale: ligne #{$item->id} ('{$item->product_name}') sans stock lié — vente sans déstockage.");
            return;
        }

        if ((float) $stock->current_quantity < (float) $item->quantity) {
            throw new Exception(
                "Stock insuffisant pour '{$item->product_name}' : " .
                "besoin {$item->quantity} {$item->unit}, disponible {$stock->current_quantity} {$stock->unit}."
            );
        }

        // Utiliser StockIntegrationService pour la traçabilité.
        // On passe l'IDENTITÉ RÉELLE du stock déjà résolu ($stock->item_name +
        // $stock->category) — et non des valeurs dérivées du product_type — afin
        // que findStock() retrouve exactement cet article, quelle que soit sa
        // catégorie (œufs, lait, aliment… mais aussi litière, matériel, etc.).
        StockIntegrationService::syncMovement(
            $stock->item_name,
            $stock->category,
            (float) $item->quantity,
            'out',
            "Vente {$item->sale->reference} — Client: {$item->sale->client->name}",
            match ($item->unit) {
                'alveole' => 'Alvéole',
                'sac'     => 'Sac',
                'litre'   => 'Litre',
                'tete'    => 'Tête',
                default   => 'KG',
            }
        );
    }

    /**
     * Décrémente l'effectif d'un lot (animal vif vendu à la tête, toute espèce).
     */
    private function destockBatch($item): void
    {
        // AUDIT C1 : même motif que destockItem — le contrôle d'effectif doit
        // être sérialisé (deux ventes parallèles du dernier sujet, sinon).
        $batch = Batch::lockForUpdate()->find($item->batch_id);

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
