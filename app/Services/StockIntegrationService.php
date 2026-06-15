<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service transversal de synchronisation des stocks.
 *
 * Appelé par : EggProductionController, MillProductionController (via ProductionService),
 * StockController::syncAll, FeedPurchaseController, et tout module qui impacte le stock.
 *
 * BUGS CORRIGÉS dans cette version :
 *
 * B-16 (Critique) : Recherche LIKE '$itemName%' → match incorrect si noms similaires
 *   → Recherche EXACTE d'abord, fallback LIKE uniquement si nécessaire + warning
 *
 * B-17 (Sérieux) : guessInputUnit() devinait l'unité par le nom (fragile)
 *   → $inputUnit est maintenant OBLIGATOIRE. guessInputUnit() conservé en fallback
 *     mais logge un warning pour traquer les appelants non migrés.
 *
 * Correction annexe : La colonne StockMovement utilisait 'description' mais le
 *   modèle/migration peut utiliser 'notes'. On utilise 'notes' (cohérent avec
 *   StockController::move qui utilise 'notes').
 */
class StockIntegrationService
{
    /**
     * Synchronise un mouvement de stock (entrée, sortie, ajustement).
     *
     * @param string      $itemName  Nom exact de l'article (ex: 'Chair Finition', 'S', 'XL')
     * @param string      $category  Catégorie stock (oeufs, conso, litieres, materiels)
     * @param float       $quantity  Quantité dans l'unité de saisie
     * @param string      $type      Type de mouvement : 'in', 'out', 'adjustment'
     * @param string      $notes     Description pour l'audit trail
     * @param string|null $inputUnit Unité de saisie (KG, Sac, Alvéole, Unité). OBLIGATOIRE recommandé.
     * @param float|null  $unitCost  Coût unitaire (par unité PIVOT) de l'entrée. Si fourni
     *                               sur un mouvement 'in', met à jour le coût moyen pondéré
     *                               (CMP) de l'article — base de valorisation de l'inventaire
     *                               et du coût de revient consommé. Ignoré sur 'out'.
     *
     * @return StockMovement|false  Le mouvement créé, ou false si article introuvable
     */
    public static function syncMovement(
        string $itemName,
        string $category,
        float  $quantity,
        string $type,
        string $notes,
        ?string $inputUnit = null,
        ?float $unitCost = null
    ): StockMovement|false {

        // ─── 1. RECHERCHE DE L'ARTICLE ───
        // B-16 corrigé : recherche EXACTE d'abord, fallback LIKE si nécessaire
        $stock = self::findStock($itemName, $category);

        if (! $stock) {
            Log::warning("StockIntegrationService: article introuvable '{$itemName}' (catégorie: {$category})");
            return false;
        }

        return DB::transaction(function () use ($stock, $itemName, $category, $quantity, $type, $notes, $inputUnit, $unitCost) {

            // ─── 2. DÉTERMINATION DE L'UNITÉ ───
            // B-17 corrigé : si $inputUnit n'est pas fourni, on logge un warning
            if (! $inputUnit) {
                $inputUnit = self::guessInputUnit($itemName, $category);
                Log::warning(
                    "StockIntegrationService: inputUnit non fourni pour '{$itemName}' ({$category}). " .
                    "Deviné: '{$inputUnit}'. Corriger l'appelant pour passer l'unité explicitement."
                );
            }

            // ─── 3. NORMALISATION VERS UNITÉ PIVOT ───
            $quantityBase = self::normalizeQuantity($quantity, $inputUnit, $category);

            // ─── 4. APPLICATION DU MOUVEMENT ───
            // Verrouillage pour éviter les race conditions
            $stock = Stock::lockForUpdate()->find($stock->id);
            $wasLow = $stock->is_low;

            if ($type === 'in') {
                // Coût moyen pondéré : on mélange l'ancien stock valorisé et la
                // nouvelle entrée. Les colonnes de prix sont persistées via le
                // 3e argument d'increment() (les attributs simplement assignés
                // ne le seraient pas par la requête atomique d'increment).
                $extra = [];
                if ($unitCost !== null && $unitCost >= 0) {
                    $oldQty   = (float) $stock->current_quantity;
                    $oldPrice = (float) ($stock->last_unit_price ?? 0);
                    $newQty   = $oldQty + $quantityBase;

                    if ($newQty > 0) {
                        $wac = round(($oldQty * $oldPrice + $quantityBase * $unitCost) / $newQty, 2);
                        $extra = ['unit_price' => $wac, 'last_unit_price' => $wac];
                    }
                }
                $stock->increment('current_quantity', $quantityBase, $extra);
            } elseif ($type === 'out') {
                // Sécurité : ne pas descendre sous zéro
                $newQty = max(0, (float) $stock->current_quantity - $quantityBase);
                $stock->update(['current_quantity' => $newQty]);
            } elseif ($type === 'adjustment') {
                $stock->update(['current_quantity' => max(0, $quantityBase)]);
            }

            // Alerte stock critique : seulement au moment où l'on FRANCHIT le
            // seuil (pas à chaque mouvement répété sous le seuil), pour éviter
            // le spam WhatsApp sur un article déjà connu comme bas.
            if (! $wasLow && $stock->is_low && $stock->alert_threshold > 0) {
                app(NotificationHub::class)->alertStockCritical($stock);
            }

            // ─── 5. ENREGISTREMENT DU MOUVEMENT ───
            return StockMovement::create([
                'stock_id' => $stock->id,
                'user_id'  => Auth::id() ?? 1,
                'type'     => $type,
                'quantity' => $quantityBase,
                'notes'    => "[SYNC] {$notes} | Saisie: {$quantity} {$inputUnit} → Appliqué: "
                            . round($quantityBase, 2) . " {$stock->unit}",
            ]);
        });
    }

    /**
     * Alias rétro-compatible.
     */
    public static function sync(
        string  $itemName,
        string  $category,
        float   $quantity,
        string  $type = 'in',
        string  $notes = '',
        ?string $unit = null
    ): StockMovement|false {
        return self::syncMovement($itemName, $category, $quantity, $type, $notes, $unit);
    }

    // ─────────────────────────────────────────────
    // MÉTHODES PRIVÉES
    // ─────────────────────────────────────────────

    /**
     * Recherche un article stock : EXACT d'abord, fallback LIKE.
     *
     * B-16 corrigé : l'ancien code faisait directement un LIKE '$itemName%'
     * ce qui matchait "Ponte Démarrage" quand on cherchait "Ponte" (faux positif).
     */
    private static function findStock(string $itemName, string $category): ?Stock
    {
        $itemName = trim($itemName);

        // 1. Recherche EXACTE (prioritaire)
        $stock = Stock::where('item_name', $itemName)
                      ->where('category', $category)
                      ->first();

        if ($stock) return $stock;

        // 2. Fallback : LIKE préfixe (pour compatibilité avec les noms abrégés)
        $stock = Stock::where('item_name', 'LIKE', $itemName . '%')
                      ->where('category', $category)
                      ->first();

        if ($stock) {
            Log::info(
                "StockIntegrationService: match approximatif '{$itemName}' → '{$stock->item_name}'. " .
                "Normaliser le nom dans l'appelant pour utiliser le nom exact."
            );
        }

        return $stock;
    }

    /**
     * Convertit la quantité saisie vers l'unité pivot du stock.
     *
     * Unités pivots :
     * - Catégorie 'conso'/'Aliment' : KG (1 Sac = 50 KG)
     * - Catégorie 'oeufs' : Alvéole (1 Alvéole = 30 unités)
     * - Autres : pas de conversion
     */
    private static function normalizeQuantity(float $quantity, string $inputUnit, string $category): float
    {
        $inputUnit = trim($inputUnit);

        if (in_array($category, [Stock::CAT_CONSO, 'Aliment'])) {
            if (strtolower($inputUnit) === 'sac') {
                return $quantity * 50; // 1 Sac = 50 KG
            }
            return $quantity; // Déjà en KG
        }

        if ($category === Stock::CAT_OEUFS) {
            $unit = strtolower($inputUnit);
            if (in_array($unit, ['unité', 'unite', 'piece', 'pièce'])) {
                return $quantity / 30; // Conversion en alvéoles
            }
            return $quantity; // Déjà en alvéoles
        }

        return $quantity; // Litières, matériels : pas de conversion
    }

    /**
     * Devine l'unité de saisie à partir du nom de l'article.
     *
     * DEPRECATED : Ce fallback ne devrait plus être nécessaire.
     * Tous les appelants doivent passer $inputUnit explicitement.
     *
     * @deprecated Passer $inputUnit directement à syncMovement()
     */
    private static function guessInputUnit(string $itemName, string $category): string
    {
        if ($category === Stock::CAT_OEUFS) {
            $name = strtoupper(trim($itemName));
            // Les calibres sont stockés en alvéoles
            if (in_array($name, ['S', 'M', 'L', 'XL', 'VENDU', 'CASSÉ', 'ANOMALIE'])) {
                return 'Alvéole';
            }
            return 'Unité';
        }

        // Aliment : tout ce qui contient chair/ponte/repro/poussin/finition/croissance/démarrage
        $lower = strtolower($itemName);
        $feedKeywords = [
            'chair', 'ponte', 'repro', 'reproducteur',
            'démarrage', 'demarrage', 'croissance', 'finition',
            'poussin', 'poulette', 'entretien', 'pic',
        ];

        foreach ($feedKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return 'KG'; // La provenderie produit en KG, pas en Sacs
            }
        }

        return 'KG'; // Par défaut
    }
}
