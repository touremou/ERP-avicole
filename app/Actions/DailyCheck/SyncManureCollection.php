<?php

namespace App\Actions\DailyCheck;

use App\Models\Batch;
use App\Models\Stock;
use App\Services\StockIntegrationService;

/**
 * Action : valorisation du fumier ramassé lors d'un renouvellement de litière.
 *
 * Les copeaux de bois étalés comme litière, mélangés aux déjections, forment
 * un fumier vendable comme fertilisant. À chaque ramassage (litière changée +
 * poids saisi), on crédite l'article de stock « Fumier » — rangé en
 * produits_finis, donc directement vendable via le module Commerce
 * (product_type « produits_finis », cf. Stock::PRODUCT_TYPE_TO_CATEGORY).
 *
 * La méthode gère la COMPENSATION : sur une rectification ou une suppression
 * de pointage, l'ancienne quantité est restituée (sortie) avant d'appliquer
 * la nouvelle (entrée), pour ne jamais double-compter le stock de fumier.
 */
class SyncManureCollection
{
    /** Nom canonique de l'article de stock fumier. */
    public const ITEM_NAME = 'Fumier';

    /**
     * Synchronise le mouvement de stock fumier.
     *
     * @param  Batch  $batch  Lot d'origine (traçabilité du ramassage).
     * @param  float  $oldKg  Quantité précédemment comptabilisée (0 à la création).
     * @param  float  $newKg  Nouvelle quantité ramassée (0 si litière non changée).
     */
    public function execute(Batch $batch, float $oldKg, float $newKg): void
    {
        // Rien à faire si aucun mouvement n'est impliqué.
        if ($oldKg <= 0 && $newKg <= 0) {
            return;
        }

        // Garantit l'existence de l'article avant tout mouvement
        // (syncMovement ignore silencieusement un article introuvable).
        $this->resolveStock();

        if ($oldKg > 0) {
            StockIntegrationService::syncMovement(
                self::ITEM_NAME,
                Stock::CAT_PRODUITS_FINIS,
                $oldKg,
                'out',
                "Rectification ramassage fumier lot {$batch->code} (annulation)",
                'KG'
            );
        }

        if ($newKg > 0) {
            StockIntegrationService::syncMovement(
                self::ITEM_NAME,
                Stock::CAT_PRODUITS_FINIS,
                $newKg,
                'in',
                "Ramassage fumier lot {$batch->code}",
                'KG'
            );
        }
    }

    /**
     * Garantit l'existence de l'article « Fumier » dans la ferme courante.
     * Le farm_id est renseigné automatiquement par le trait BelongsToFarm.
     */
    private function resolveStock(): Stock
    {
        return Stock::firstOrCreate(
            ['item_name' => self::ITEM_NAME, 'category' => Stock::CAT_PRODUITS_FINIS],
            [
                'unit'             => 'KG',
                'current_quantity' => 0,
                'alert_threshold'  => 0,
                'metadata'         => ['poultry_type' => 'Sous-produit', 'is_byproduct' => true],
            ]
        );
    }
}
