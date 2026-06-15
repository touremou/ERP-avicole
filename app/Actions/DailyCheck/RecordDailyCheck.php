<?php

namespace App\Actions\DailyCheck;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Action : Enregistrement d'un pointage journalier.
 *
 * Gère :
 * 1. Le updateOrCreate atomique (un seul pointage par lot et par jour)
 * 2. La vérification de stock aliment AVANT écriture
 * 3. La compensation stock si c'est une mise à jour (annule l'ancien mouvement)
 * 4. Le mouvement de sortie stock (via StockIntegrationService)
 *
 * L'impact sur Batch::current_quantity est géré par DailyCheckObserver.
 */
class RecordDailyCheck
{
    /**
     * @param  array $data  Données validées depuis StoreDailyCheckRequest
     * @return DailyCheck Le pointage créé ou mis à jour
     */
    public function execute(array $data): DailyCheck
    {
        return DB::transaction(function () use ($data) {
            $batch = Batch::findOrFail($data['batch_id']);

            // ─── Chercher un pointage existant pour cette date ───
            $existing = DailyCheck::where('batch_id', $data['batch_id'])
                ->where('check_date', $data['check_date'])
                ->first();

            // ─── Vérification stock aliment ───
            $feedType = trim($data['feed_type']);
            $feedConsumed = (float) ($data['feed_consumed'] ?? 0);

            if ($feedConsumed > 0) {
                $this->checkFeedStock($feedType, $feedConsumed, $existing);
            }

            // ─── Compensation stock si mise à jour ───
            if ($existing && $feedConsumed > 0) {
                // Restituer l'ancienne consommation
                if ((float) $existing->feed_consumed > 0) {
                    StockIntegrationService::syncMovement(
                        $existing->feed_type,
                        'conso',
                        (float) $existing->feed_consumed,
                        'in',
                        "Correction pointage lot {$batch->code} (annulation ancienne conso)",
                        'KG'
                    );
                }
            }

            // Fumier : quantités avant/après pour compensation (ramassage
            // ressaisi sur un pointage déjà existant à la même date).
            $oldManure = (float) ($existing->manure_collected_kg ?? 0);
            $newManure = (float) ($data['manure_collected_kg'] ?? 0);

            // ─── Coût de revient de l'aliment consommé (snapshot CMP) ───
            // On fige le coût moyen pondéré courant de l'article aliment afin
            // de valoriser cette consommation dans la marge du lot, qu'il
            // s'agisse d'aliment acheté ou produit à la provenderie.
            if ($feedConsumed > 0) {
                $data['feed_unit_cost'] = $this->resolveFeedUnitCost($feedType);
            }

            // ─── Création ou mise à jour du pointage ───
            // Note : l'observer DailyCheckObserver gère l'impact sur current_quantity
            $check = DailyCheck::updateOrCreate(
                [
                    'batch_id'   => $data['batch_id'],
                    'check_date' => $data['check_date'],
                ],
                $data
            );

            // ─── Mouvement de sortie stock aliment ───
            if ($feedConsumed > 0) {
                StockIntegrationService::syncMovement(
                    $feedType,
                    'conso',
                    $feedConsumed,
                    'out',
                    "Consommation journalière lot {$batch->code}",
                    'KG'
                );
            }

            // ─── Valorisation du fumier ramassé (sous-produit fertilisant) ───
            app(SyncManureCollection::class)->execute($batch, $oldManure, $newManure);

            return $check;
        });
    }

    /**
     * Coût moyen pondéré courant (par KG) de l'article aliment consommé.
     * Retourne 0 si l'article n'a pas encore de valorisation.
     */
    private function resolveFeedUnitCost(string $feedType): float
    {
        $stock = Stock::where('feed_type', trim($feedType))
            ->where('category', Stock::CAT_CONSO)
            ->first();

        return (float) ($stock?->last_unit_price ?? $stock?->unit_price ?? 0);
    }

    /**
     * Vérifie que le stock aliment est suffisant.
     *
     * @throws ValidationException Si stock insuffisant
     */
    private function checkFeedStock(string $feedType, float $requested, ?DailyCheck $existing): void
    {
        // 1. Utilisation de la nouvelle clé stricte 'feed_type'
        $stock = Stock::where('feed_type', trim($feedType))
            ->where('category', Stock::CAT_CONSO)
            ->first();

        // 2. Conversion automatique en KG si le stock est géré en Sacs
        $availableKg = 0;
        if ($stock) {
            $availableKg = (strtolower($stock->unit) === 'sac') 
                ? (float) $stock->current_quantity * 50 
                : (float) $stock->current_quantity;
        }

        // Si c'est une mise à jour du même type d'aliment, on "rend" l'ancien stock virtuellement
        if ($existing && trim($existing->feed_type) === trim($feedType)) {
            $availableKg += (float) $existing->feed_consumed;
        }

        if ($requested > $availableKg) {
            throw ValidationException::withMessages([
                'feed_consumed' => "Stock insuffisant pour {$feedType}. Disponible : " .
                    number_format($availableKg, 1) . " kg, demandé : " .
                    number_format($requested, 1) . " kg.",
            ]);
        }
    }
}