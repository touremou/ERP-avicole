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
     *
     * Motif audit (drills C1/C3/C5) : verrou → relecture verrouillante →
     * contrôle → écriture, DANS la transaction. Statut, effectif et
     * quarantaine sont re-contrôlés sous verrou — un double-clic ne
     * décrémente pas deux fois, un lot qui a maigri depuis l'ordre ne passe
     * pas en négatif, la viande d'un lot en quarantaine n'entre jamais en
     * stock alimentaire.
     */
    public function executeSlaughter(SlaughterOrder $order, array $data): SlaughterResult
    {
        return DB::transaction(function () use ($order, $data) {
            // Anti-rejeu : statut relu SOUS verrou (le contrôle hors
            // transaction laissait passer le double-clic — motif C3).
            $order = SlaughterOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'planifie') {
                throw new Exception("L'ordre {$order->order_number} n'est pas en attente (statut: {$order->status}).");
            }

            $actualQty = (int) $data['actual_quantity'];
            $liveWeight = (float) $data['total_live_weight_kg'];
            $carcassWeight = (float) $data['total_carcass_weight_kg'];
            $condemned = (int) ($data['condemned_count'] ?? 0);

            // Lot source relu SOUS verrou : l'effectif a pu changer depuis la
            // création de l'ordre (mortalité, ventes) et une quarantaine a pu
            // être posée entre-temps.
            $batch = $order->batch_id ? Batch::lockForUpdate()->find($order->batch_id) : null;
            if ($batch) {
                if ($batch->status !== 'Actif') {
                    throw new Exception("Le lot {$batch->code} n'est plus actif (statut : {$batch->status}) — abattage impossible.");
                }

                // Biosécurité : viande d'un lot sous traitement = délai
                // d'attente non purgé. Blocage dur, levée via le module Santé.
                if ($quarantine = $batch->activeQuarantine()) {
                    throw new Exception(
                        "Le lot {$batch->code} est en QUARANTAINE sanitaire (incident n°{$quarantine->id}) — "
                        . "abattage et mise en stock alimentaire interdits jusqu'à la levée."
                    );
                }

                if ($actualQty > (int) $batch->current_quantity) {
                    throw new Exception(
                        "Effectif insuffisant : {$actualQty} sujets à abattre mais le lot {$batch->code} "
                        . "n'en compte plus que {$batch->current_quantity} (mortalité/ventes depuis l'ordre)."
                    );
                }
            }

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

            // 3. Décrémenter le lot source (déjà verrouillé et contrôlé en tête)
            if ($batch) {
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
            //    RG-07 : JAMAIS pour un abattage à façon — les produits restent
            //    propriété du client et ne rejoignent pas le stock vendable.
            if (! $order->isFacon()) {
                $productName = $this->carcassProductName($batch);
                $this->addToFinishedStock($productName, 'entier_frais', $carcassWeight, $effectiveQty, 'frais');
            }

            // 5. Façon (E8) : calcul de la prestation selon le modèle figé sur
            //    l'ordre + facture brouillon dans le module Commerce.
            if ($order->isFacon()) {
                app(\App\Actions\Slaughter\BillTollSlaughter::class)->execute($order->fresh());
            }

            Log::info("Abattage {$order->order_number} : {$actualQty} sujets, {$liveWeight}kg vif → {$carcassWeight}kg carcasse (rendement {$yieldPercent}%)" . ($order->isFacon() ? ' [FAÇON]' : ''));

            return $result;
        });
    }

    /**
     * Enregistre une session de découpe.
     *
     * Conservation de matière : la somme des entrées de TOUTES les sessions
     * de l'ordre ne peut pas dépasser la carcasse produite par l'abattage
     * (sans ce plafond, removeFromFinishedStock — silencieux et borné —
     * laissait créer des morceaux fantômes au-delà du stock réel). Le verrou
     * de l'ordre sérialise les sessions concurrentes.
     */
    public function executeCutting(SlaughterOrder $order, array $data): CuttingSession
    {
        return DB::transaction(function () use ($order, $data) {
            $order = SlaughterOrder::lockForUpdate()->findOrFail($order->id);

            if ($order->status !== 'termine') {
                throw new Exception("L'abattage de l'ordre {$order->order_number} doit être terminé avant la découpe.");
            }

            $carcassKg  = (float) ($order->result?->total_carcass_weight_kg ?? 0);
            $alreadyCut = (float) $order->cuttingSessions()->sum('total_input_kg');
            $inputKg    = (float) $data['total_input_kg'];

            if ($inputKg + $alreadyCut > $carcassKg + 0.001) {
                $remaining = max(0, $carcassKg - $alreadyCut);
                throw new Exception(
                    "Conservation de matière : {$inputKg} kg demandés mais il ne reste que "
                    . number_format($remaining, 1) . " kg de carcasse à découper sur l'ordre {$order->order_number} "
                    . "(carcasse produite : " . number_format($carcassKg, 1) . " kg, déjà découpé : "
                    . number_format($alreadyCut, 1) . " kg)."
                );
            }

            $session = CuttingSession::create([
                'slaughter_order_id' => $order->id,
                'session_date'       => $data['session_date'] ?? now()->toDateString(),
                'operator_id'        => Auth::id(),
                'total_input_kg'     => $data['total_input_kg'],
            ]);

            // Retirer du stock "entier frais" le poids découpé (nom selon
            // l'espèce du lot abattu — cf. carcassProductName()).
            // RG-07 : un ordre à façon n'a RIEN mis en stock à l'abattage —
            // sa découpe ne touche donc pas le stock de l'entreprise (les
            // morceaux repartent avec le client, tracés en CutProduct).
            if (! $order->isFacon()) {
                $sourceProductName = $this->carcassProductName($order->batch);
                $this->removeFromFinishedStock($sourceProductName, 'entier_frais', (float) $data['total_input_kg']);
            }

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

                // Entrer en stock produits finis selon destination.
                // RG-07 : jamais pour la façon (propriété du client).
                if (! $order->isFacon() && ($product['destination'] ?? 'stock_frais') !== 'transformation') {
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

            // ═══ 1. VÉRIFIER LE STOCK SOURCE (sous verrou — deux
            //     transformations simultanées du même stock se sérialisent,
            //     le contrôle ne peut plus être doublé : motif C1) ═══
            $sourceProduct = FinishedProduct::where('product_name', $sourceName)
                ->where('current_quantity_kg', '>', 0)
                ->lockForUpdate()
                ->first();

            if (! $sourceProduct) {
                throw new Exception("Produit source \"{$sourceName}\" introuvable ou stock vide.");
            }

            // Cohérence physique : fumage/grillage PERDENT de l'eau, la
            // marinade peut en gagner un peu — au-delà de ×1,5 c'est une
            // erreur de pesée (kg/pièce au lieu du total, etc.).
            if ($outputKg > 0 && $outputKg > $inputKg * 1.5) {
                throw new Exception(
                    "Rendement aberrant : " . number_format($outputKg, 1) . " kg produits pour "
                    . number_format($inputKg, 1) . " kg engagés (" . round(($outputKg / $inputKg) * 100)
                    . " %). Vérifiez les deux pesées."
                );
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
     * Termine une transformation restée « en cours » (fumage/grillage saisi
     * à l'engagement de la matière, pesée de sortie connue des heures plus
     * tard) : enregistre la sortie, calcule le rendement et entre le produit
     * transformé en stock. Idempotent sous verrou : une transformation déjà
     * terminée ne peut pas être re-terminée (pas de double entrée en stock).
     */
    public function completeTransformation(Transformation $transformation, float $outputKg): Transformation
    {
        return DB::transaction(function () use ($transformation, $outputKg) {
            $transformation = Transformation::lockForUpdate()->findOrFail($transformation->id);

            if ($transformation->status !== 'en_cours') {
                throw new Exception("La transformation {$transformation->batch_number} est déjà terminée.");
            }

            $inputKg = (float) $transformation->input_kg;
            if ($outputKg > $inputKg * 1.5) {
                throw new Exception(
                    "Rendement aberrant : " . number_format($outputKg, 1) . " kg produits pour "
                    . number_format($inputKg, 1) . " kg engagés. Vérifiez la pesée de sortie."
                );
            }

            $transformation->update([
                'output_kg'     => $outputKg,
                'yield_percent' => $inputKg > 0 ? round(($outputKg / $inputKg) * 100, 2) : 0,
                'status'        => 'termine',
            ]);

            $productName = ucfirst($transformation->product_source) . ' ' . $transformation->type_label;
            $this->addToFinishedStock(
                $productName,
                $transformation->transformation_type,
                $outputKg,
                0,
                $transformation->transformation_type === 'fume' ? 'fumoir' : 'vitrine'
            );

            Log::info("Transformation {$transformation->batch_number} terminée : {$inputKg}kg → {$outputKg}kg ({$transformation->yield_percent}%)");

            return $transformation->fresh();
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
