<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CutProduct;
use App\Models\CuttingSession;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\Transformation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SlaughterService
{
    /**
     * Exécute un abattage : pesée vif, abattage, pesée carcasse, mise en stock.
     */
    public function executeSlaughter(SlaughterOrder $order, array $data): SlaughterResult
    {
        if ($order->status !== 'planifie') {
            throw new Exception("L'ordre {$order->order_number} n'est pas en attente (statut: {$order->status}).");
        }

        return DB::transaction(function () use ($order, $data) {
            $actualQty = (int) $data['actual_quantity'];
            $liveWeight = (float) $data['total_live_weight_kg'];
            $carcassWeight = (float) $data['total_carcass_weight_kg'];
            $condemned = (int) ($data['condemned_count'] ?? 0);

            // Calculs
            $effectiveQty = $actualQty - $condemned;
            $yieldPercent = $liveWeight > 0 ? round(($carcassWeight / $liveWeight) * 100, 2) : 0;
            $avgLive = $actualQty > 0 ? round($liveWeight / $actualQty, 3) : 0;
            $avgCarcass = $effectiveQty > 0 ? round($carcassWeight / $effectiveQty, 3) : 0;

            // 1. Enregistrer le résultat
            $result = SlaughterResult::create([
                'slaughter_order_id'   => $order->id,
                'total_carcass_weight_kg' => $carcassWeight,
                'carcass_yield_percent' => $yieldPercent,
                'condemned_count'      => $condemned,
                'condemned_reason'     => $data['condemned_reason'] ?? null,
                'avg_live_weight_kg'   => $avgLive,
                'avg_carcass_weight_kg' => $avgCarcass,
                'execution_date'       => $data['execution_date'] ?? now()->toDateString(),
                'inspector_notes'      => $data['inspector_notes'] ?? null,
            ]);

            // 2. Mettre à jour l'ordre
            $order->update([
                'status'               => 'termine',
                'actual_date'          => $data['execution_date'] ?? now()->toDateString(),
                'actual_quantity'      => $actualQty,
                'total_live_weight_kg' => $liveWeight,
                'executed_by'          => Auth::id(),
            ]);

            // 3. Décrémenter le lot source
            $batch = $order->batch;
            if ($batch && $batch->status === 'Actif') {
                $batch->decrement('current_quantity', $actualQty);

                // Si tout le lot est abattu, fermer le lot
                if ($batch->current_quantity <= 0) {
                    $batch->update([
                        'status'       => 'Terminé',
                        'closing_date' => now(),
                    ]);
                }
            }

            // 4. Entrer les carcasses en stock produits finis (nom selon
            //    l'espèce du lot — multiespèces : "Poulet", "Chèvre", "Mouton"...)
            $productName = $this->carcassProductName($batch);
            $this->addToFinishedStock($productName, 'entier_frais', $carcassWeight, $effectiveQty, 'frais');

            Log::info("Abattage {$order->order_number} : {$actualQty} sujets, {$liveWeight}kg vif → {$carcassWeight}kg carcasse (rendement {$yieldPercent}%)");

            return $result;
        });
    }

    /**
     * Enregistre une session de découpe.
     */
    public function executeCutting(SlaughterOrder $order, array $data): CuttingSession
    {
        return DB::transaction(function () use ($order, $data) {
            $session = CuttingSession::create([
                'slaughter_order_id' => $order->id,
                'session_date'       => $data['session_date'] ?? now()->toDateString(),
                'operator_id'        => Auth::id(),
                'total_input_kg'     => $data['total_input_kg'],
            ]);

            // Retirer du stock "entier frais" le poids découpé (nom selon
            // l'espèce du lot abattu — cf. carcassProductName())
            $sourceProductName = $this->carcassProductName($order->batch);
            $this->removeFromFinishedStock($sourceProductName, 'entier_frais', (float) $data['total_input_kg']);

            // Enregistrer chaque produit de découpe
            foreach ($data['products'] as $product) {
                CutProduct::create([
                    'cutting_session_id' => $session->id,
                    'product_type'       => $product['type'],
                    'product_name'       => $product['name'],
                    'quantity_kg'        => $product['kg'],
                    'quantity_pieces'    => $product['pieces'] ?? null,
                    'unit_price'         => $product['price'] ?? null,
                    'destination'        => $product['destination'] ?? 'stock_frais',
                ]);

                // Entrer en stock produits finis selon destination
                if (($product['destination'] ?? 'stock_frais') !== 'transformation') {
                    $storage = match ($product['destination'] ?? 'stock_frais') {
                        'stock_congele' => 'congele',
                        'vente_directe' => 'vitrine',
                        default         => 'frais',
                    };

                    $this->addToFinishedStock(
                        $product['name'],
                        $product['type'],
                        (float) $product['kg'],
                        (int) ($product['pieces'] ?? 0),
                        $storage
                    );
                }
            }

            $session->recalculateLoss();

            Log::info("Découpe session #{$session->id} — Entrée: {$data['total_input_kg']}kg, Sortie: {$session->total_output_kg}kg, Perte: {$session->loss_percent}%");

            return $session->fresh('products');
        });
    }

    /**
     * Enregistre une transformation (fumage, grillage, etc.).
     *
     * 1. Vérifie que le stock source est suffisant
     * 2. Déduit le poids entrant du produit source
     * 3. Crée la transformation
     * 4. Ajoute le produit transformé au stock (si terminé)
     */
    public function executeTransformation(array $data): Transformation
    {
        return DB::transaction(function () use ($data) {
            $inputKg = (float) $data['input_kg'];
            $outputKg = (float) ($data['output_kg'] ?? 0);
            $sourceName = $data['product_source'];

            // ═══ 1. VÉRIFIER LE STOCK SOURCE ═══
            $sourceProduct = FinishedProduct::where('product_name', $sourceName)
                ->where('current_quantity_kg', '>', 0)
                ->first();

            if (! $sourceProduct) {
                throw new Exception("Produit source \"{$sourceName}\" introuvable ou stock vide.");
            }

            if ((float) $sourceProduct->current_quantity_kg < $inputKg) {
                throw new Exception(
                    "Stock insuffisant pour \"{$sourceName}\" : " .
                    number_format($sourceProduct->current_quantity_kg, 1) . " kg disponibles, " .
                    number_format($inputKg, 1) . " kg demandés."
                );
            }

            // ═══ 2. DÉDUIRE DU STOCK SOURCE ═══
            $sourceProduct->decrement('current_quantity_kg', $inputKg);

            // Déduire aussi les pièces proportionnellement si applicable
            if ($sourceProduct->current_quantity_pieces > 0 && $sourceProduct->current_quantity_kg > 0) {
                $originalKg = (float) $sourceProduct->current_quantity_kg + $inputKg;
                $piecesToRemove = (int) round(($inputKg / $originalKg) * $sourceProduct->current_quantity_pieces);
                if ($piecesToRemove > 0) {
                    $sourceProduct->decrement('current_quantity_pieces', $piecesToRemove);
                }
            }

            Log::info("Transformation: {$inputKg}kg déduits de \"{$sourceName}\" (restant: {$sourceProduct->fresh()->current_quantity_kg}kg)");

            // ═══ 3. CRÉER LA TRANSFORMATION ═══
            $yieldPercent = $inputKg > 0 ? round(($outputKg / $inputKg) * 100, 2) : 0;

            $transformation = Transformation::create([
                'batch_number'        => Transformation::generateBatchNumber(),
                'product_source'      => $sourceName,
                'transformation_type' => $data['type'],
                'input_kg'            => $inputKg,
                'output_kg'           => $outputKg,
                'yield_percent'       => $yieldPercent,
                'production_date'     => $data['production_date'] ?? now()->toDateString(),
                'expiry_date'         => $data['expiry_date'] ?? null,
                'operator_id'         => Auth::id(),
                'production_cost'     => $data['cost'] ?? 0,
                'status'              => $outputKg > 0 ? 'termine' : 'en_cours',
                'notes'               => $data['notes'] ?? null,
            ]);

            // ═══ 4. ENTRER LE PRODUIT TRANSFORMÉ EN STOCK (si terminé) ═══
            if ($outputKg > 0) {
                $productName = ucfirst($sourceName) . ' ' . $transformation->type_label;
                $this->addToFinishedStock(
                    $productName,
                    $data['type'],
                    $outputKg,
                    0,
                    $data['type'] === 'fume' ? 'fumoir' : 'vitrine'
                );
            }

            Log::info("Transformation {$transformation->batch_number} : {$inputKg}kg {$data['product_source']} → {$outputKg}kg {$data['type']} (rendement {$yieldPercent}%)");

            return $transformation;
        });
    }

    /**
     * Nom du produit "carcasse entière fraîche" selon l'espèce du lot abattu
     * (multiespèces : "Poulet Entier Frais", "Chèvre Entier Frais"...).
     * Repli sur "Poulet" si le lot n'a pas d'espèce renseignée (rétrocompat).
     */
    private function carcassProductName(?Batch $batch): string
    {
        $speciesName = $batch?->species?->name_fr ?? 'Poulet';

        return "{$speciesName} Entier Frais";
    }

    /**
     * Ajoute au stock produits finis (upsert).
     */
    private function addToFinishedStock(string $name, string $type, float $kg, int $pieces, string $location): void
    {
        $product = FinishedProduct::firstOrCreate(
            ['product_name' => $name, 'product_type' => $type, 'storage_location' => $location],
            ['unit' => 'kg', 'unit_price' => 0]
        );

        $product->increment('current_quantity_kg', $kg);
        if ($pieces > 0) {
            $product->increment('current_quantity_pieces', $pieces);
        }
    }

    /**
     * Retire du stock produits finis.
     */
    private function removeFromFinishedStock(string $name, string $type, float $kg): void
    {
        $product = FinishedProduct::where('product_name', $name)
            ->where('product_type', $type)
            ->first();

        if ($product) {
            $product->decrement('current_quantity_kg', min($kg, (float) $product->current_quantity_kg));
        }
    }

    /**
     * KPI abattoir sur une période.
     */
    public function getKPI(int $days = 30): array
    {
        $from = now()->subDays($days);

        $results = SlaughterResult::where('execution_date', '>=', $from)->get();
        $orders = SlaughterOrder::where('status', 'termine')->where('actual_date', '>=', $from)->get();

        $totalSlaughtered = $orders->sum('actual_quantity');
        $totalLiveKg = $orders->sum('total_live_weight_kg');
        $totalCarcassKg = $results->sum('total_carcass_weight_kg');
        $totalCondemned = $results->sum('condemned_count');
        $avgYield = $results->avg('carcass_yield_percent') ?? 0;

        $cuttings = CuttingSession::where('session_date', '>=', $from)->get();
        $avgCuttingLoss = $cuttings->avg('loss_percent') ?? 0;

        $transformations = Transformation::where('production_date', '>=', $from)->where('status', 'termine')->get();
        $avgTransformYield = $transformations->avg('yield_percent') ?? 0;

        return [
            'total_slaughtered'  => $totalSlaughtered,
            'total_live_kg'      => round($totalLiveKg, 1),
            'total_carcass_kg'   => round($totalCarcassKg, 1),
            'avg_yield'          => round($avgYield, 1),
            'total_condemned'    => $totalCondemned,
            'condemnation_rate'  => $totalSlaughtered > 0 ? round(($totalCondemned / $totalSlaughtered) * 100, 2) : 0,
            'avg_cutting_loss'   => round($avgCuttingLoss, 1),
            'avg_transform_yield' => round($avgTransformYield, 1),
            'orders_count'       => $orders->count(),
        ];
    }
}
