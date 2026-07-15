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
 * L'impact sur Batch::current_quantity est géré par DailyCheck::booted().
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

            // ─── Normalisation de la date (cohérence updateOrCreate) ───
            // La colonne check_date est persistée au format datetime
            // (« Y-m-d 00:00:00 ») via le cast 'date'. Une comparaison avec la
            // chaîne brute « Y-m-d » ne matchait jamais la ligne stockée : le
            // pointage existant n'était pas retrouvé, et updateOrCreate tentait
            // un INSERT → violation de contrainte UNIQUE (500) à chaque
            // correction d'un pointage du jour. On fige donc une instance
            // Carbon en début de journée, utilisée pour la recherche ET pour
            // le updateOrCreate, afin que les deux formats coïncident.
            $data['check_date'] = \Illuminate\Support\Carbon::parse($data['check_date'])->startOfDay();

            // ─── Chercher un pointage existant pour cette date ───
            $existing = DailyCheck::where('batch_id', $data['batch_id'])
                ->where('check_date', $data['check_date'])
                ->first();

            // ─── Cohérence INFIRMERIE : on ne peut pas sortir (rétablis) ni
            //     déclarer morts plus de sujets qu'il n'y en a d'isolés. Le
            //     solde disponible exclut le pointage en cours de correction.
            $infirmaryOutflow = (int) ($data['qty_quarantine_out'] ?? 0)
                              + (int) ($data['mortality_infirmary'] ?? 0);
            $infirmaryInflow = (int) ($data['qty_quarantine_in'] ?? 0);

            if ($infirmaryOutflow > 0) {
                $available = $batch->infirmaryCountExcluding($existing?->id) + $infirmaryInflow;
                if ($infirmaryOutflow > $available) {
                    throw ValidationException::withMessages([
                        'qty_quarantine_out' => "Infirmerie : {$infirmaryOutflow} sortie(s) déclarée(s) "
                            . "(rétablis + morts) mais seulement {$available} sujet(s) isolé(s) disponible(s).",
                    ]);
                }
            }

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

            // Eau : litres avant/après pour imputer le delta à la citerne.
            $oldWater = (float) ($existing->water_consumed ?? 0);
            $newWater = (float) ($data['water_consumed'] ?? 0);

            // ─── Coût de revient de l'aliment consommé (snapshot CMP) ───
            // On fige le coût moyen pondéré courant de l'article aliment afin
            // de valoriser cette consommation dans la marge du lot, qu'il
            // s'agisse d'aliment acheté ou produit à la provenderie.
            if ($feedConsumed > 0) {
                $data['feed_unit_cost'] = $this->resolveFeedUnitCost($feedType);
            }

            // ─── Uniformité AUTOMATISÉE depuis les pesées d'échantillon ───
            // Si des pesées individuelles sont fournies, le SERVEUR recalcule
            // poids moyen + taux d'uniformité et écrase les valeurs client
            // (formule documentée : DailyCheck::computeSampleStats).
            if (! empty($data['weight_samples']) && is_array($data['weight_samples'])) {
                $stats = DailyCheck::computeSampleStats($data['weight_samples']);
                if ($stats) {
                    $data['weight_samples'] = $stats['samples'];
                    $data['avg_weight']     = $stats['avg_weight'];
                    if ($stats['uniformity_pct'] !== null) {
                        $data['uniformity_pct'] = $stats['uniformity_pct'];
                    }
                } else {
                    unset($data['weight_samples']);
                }
            }

            // ─── Création ou mise à jour du pointage ───
            // Note : DailyCheck::booted() gère l'impact sur current_quantity
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

            // ─── Imputation de la consommation d'eau à la citerne du bâtiment ───
            app(SyncWaterConsumption::class)->execute($batch, $oldWater, $newWater);

            return $check;
        });
    }

    /**
     * Coût moyen pondéré courant (par KG) de l'article aliment consommé.
     * Retourne 0 si l'article n'a pas encore de valorisation.
     */
    private function resolveFeedUnitCost(string $feedType): float
    {
        $stock = self::findFeedStock($feedType);

        return (float) ($stock?->last_unit_price ?? $stock?->unit_price ?? 0);
    }

    /**
     * Recherche un article aliment par item_name ou feed_type (les deux colonnes
     * peuvent porter la valeur selon la voie de création du stock).
     */
    private static function findFeedStock(string $feedType): ?Stock
    {
        $name = trim($feedType);
        return Stock::where('category', Stock::CAT_CONSO)
            ->where(fn ($q) => $q->where('item_name', $name)->orWhere('feed_type', $name))
            ->first();
    }

    /**
     * Vérifie que le stock aliment est suffisant.
     *
     * @throws ValidationException Si stock insuffisant
     */
    private function checkFeedStock(string $feedType, float $requested, ?DailyCheck $existing): void
    {
        $stock = self::findFeedStock($feedType);

        // 2. Conversion automatique en KG si le stock est géré en Sacs
        $availableKg = 0;
        if ($stock) {
            $availableKg = \App\Services\UnitConverter::toStockBase(
                (float) $stock->current_quantity,
                $stock->unit,
                \App\Models\Stock::CAT_CONSO,
                $stock->metadata['bag_weight'] ?? null
            );
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