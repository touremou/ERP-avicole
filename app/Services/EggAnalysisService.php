<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\ProductionNorm;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * EggAnalysisService — Analyse de la production d'œufs et détection d'anomalies.
 *
 * Irrégularités détectées :
 * 1. Chute HDP > 5% par rapport à la veille
 * 2. HDP sous la norme du modèle/âge (ISA Brown, Lohmann, etc.)
 * 3. Taux d'œufs cassés/anomalies > 3%
 * 4. Lot pondeuse actif SANS collecte depuis 24h
 * 5. Pic d'œufs petits calibre (S) > 20% → problème nutritionnel
 * 6. Chute brutale de la production totale (< 70% de la moyenne 7j)
 */
class EggAnalysisService
{
    /**
     * Analyse complète pour le résumé quotidien.
     */
    public function getDailyReport(): array
    {
        $yesterday = Carbon::yesterday();
        $dayBefore = Carbon::yesterday()->subDay();

        // Lots pondeuses actifs
        $layingBatches = Batch::active()
            ->byType('ponte')
            ->with('building')
            ->get();

        if ($layingBatches->isEmpty()) {
            return [
                'has_layers'    => false,
                'collections'   => collect(),
                'irregularities' => [],
                'summary'       => null,
            ];
        }

        // Collectes d'hier
        $yesterdayCollections = EggProduction::whereDate('collection_date', $yesterday)
            ->with('batch.building')
            ->get();

        // Collectes avant-hier (pour comparaison)
        $dayBeforeCollections = EggProduction::whereDate('collection_date', $dayBefore)->get();

        // Analyse
        $irregularities = [];
        $totalEggs = 0;
        $totalBroken = 0;
        $totalAnomalies = 0;
        $batchReports = [];

        foreach ($layingBatches as $batch) {
            $collection = $yesterdayCollections->where('batch_id', $batch->id)->first();
            $prevCollection = $dayBeforeCollections->where('batch_id', $batch->id)->first();

            // Calcul de l'âge en semaines
            $ageWeeks = $batch->arrival_date
                ? Carbon::parse($batch->arrival_date)->diffInWeeks(now())
                : null;

            // ─── IRRÉGULARITÉ 1 : PAS DE COLLECTE ───
            if (! $collection) {
                $irregularities[] = [
                    'type'     => 'missing_collection',
                    'severity' => 'attention',
                    'batch'    => $batch->code,
                    'building' => $batch->building->name ?? '?',
                    'message'  => "Aucune collecte enregistrée hier",
                    'emoji'    => '❓',
                ];
                $batchReports[] = [
                    'batch'    => $batch,
                    'eggs'     => 0,
                    'hdp'      => 0,
                    'status'   => 'missing',
                ];
                continue;
            }

            // Calcul HDP
            $totalCollected = (float) ($collection->total_collected ?? 0);
            $hens = (int) $batch->current_quantity;
            $hdp = ($hens > 0) ? round(($totalCollected / $hens) * 100, 1) : 0;

            // HDP de la veille
            $prevTotal = $prevCollection ? (float) ($prevCollection->total_collected ?? 0) : null;
            $prevHdp = ($prevTotal !== null && $hens > 0) ? round(($prevTotal / $hens) * 100, 1) : null;

            // Œufs cassés et anomalies
            $broken = (float) ($collection->broken ?? $collection->qty_broken ?? 0);
            $anomalies = (float) ($collection->anomalies ?? $collection->qty_anomaly ?? 0);
            $brokenRate = ($totalCollected > 0) ? round((($broken + $anomalies) / $totalCollected) * 100, 1) : 0;

            // Calibres
            $qtyS = (float) ($collection->qty_small ?? $collection->calibre_s ?? 0);
            $smallRate = ($totalCollected > 0) ? round(($qtyS / $totalCollected) * 100, 1) : 0;

            $totalEggs += $totalCollected;
            $totalBroken += $broken;
            $totalAnomalies += $anomalies;

            $batchReports[] = [
                'batch'       => $batch,
                'eggs'        => $totalCollected,
                'hdp'         => $hdp,
                'prev_hdp'    => $prevHdp,
                'broken'      => $broken,
                'broken_rate' => $brokenRate,
                'status'      => 'ok',
            ];

            // ─── IRRÉGULARITÉ 2 : CHUTE HDP > 5% ───
            if ($prevHdp !== null && $prevHdp > 0 && ($prevHdp - $hdp) > 5) {
                $drop = round($prevHdp - $hdp, 1);
                $irregularities[] = [
                    'type'     => 'hdp_drop',
                    'severity' => $drop > 10 ? 'critique' : 'attention',
                    'batch'    => $batch->code,
                    'building' => $batch->building->name ?? '?',
                    'message'  => "HDP en chute : {$hdp}% (veille {$prevHdp}%, -{$drop}%)",
                    'emoji'    => $drop > 10 ? '🔴' : '📉',
                ];
            }

            // ─── IRRÉGULARITÉ 3 : HDP SOUS LA NORME ───
            $normHdp = $this->getNormHdp($batch->model_name, $ageWeeks);
            if ($normHdp && $hdp < ($normHdp * 0.85)) {
                $irregularities[] = [
                    'type'     => 'hdp_below_norm',
                    'severity' => 'attention',
                    'batch'    => $batch->code,
                    'building' => $batch->building->name ?? '?',
                    'message'  => "HDP {$hdp}% < norme {$normHdp}% ({$batch->model_name}, sem. {$ageWeeks})",
                    'emoji'    => '📊',
                ];
            }

            // ─── IRRÉGULARITÉ 4 : TAUX CASSE > 3% ───
            if ($brokenRate > 3) {
                $irregularities[] = [
                    'type'     => 'high_breakage',
                    'severity' => $brokenRate > 5 ? 'critique' : 'attention',
                    'batch'    => $batch->code,
                    'building' => $batch->building->name ?? '?',
                    'message'  => "Taux casse/anomalies : {$brokenRate}% ({$broken} cassés, {$anomalies} anomalies)",
                    'emoji'    => '🥚💔',
                ];
            }

            // ─── IRRÉGULARITÉ 5 : TROP DE PETITS CALIBRES ───
            if ($smallRate > 20 && $totalCollected > 30) {
                $irregularities[] = [
                    'type'     => 'small_eggs',
                    'severity' => 'attention',
                    'batch'    => $batch->code,
                    'building' => $batch->building->name ?? '?',
                    'message'  => "Calibre S = {$smallRate}% → vérifier alimentation (calcium, protéines)",
                    'emoji'    => '🔬',
                ];
            }
        }

        // ─── IRRÉGULARITÉ 6 : CHUTE PRODUCTION GLOBALE ───
        $avg7days = EggProduction::where('collection_date', '>=', now()->subDays(8))
            ->where('collection_date', '<', $yesterday)
            ->avg('total_collected');

        if ($avg7days && $avg7days > 0 && $totalEggs < ($avg7days * 0.7 * $layingBatches->count())) {
            $irregularities[] = [
                'type'     => 'global_drop',
                'severity' => 'critique',
                'batch'    => 'GLOBAL',
                'building' => 'Tous',
                'message'  => "Production totale ({$totalEggs} œufs) < 70% de la moyenne 7j",
                'emoji'    => '🚨',
            ];
        }

        // Résumé global
        $globalHdp = 0;
        $totalHens = $layingBatches->sum('current_quantity');
        if ($totalHens > 0) {
            $globalHdp = round(($totalEggs / $totalHens) * 100, 1);
        }

        return [
            'has_layers'     => true,
            'total_eggs'     => $totalEggs,
            'total_broken'   => $totalBroken,
            'total_anomalies' => $totalAnomalies,
            'global_hdp'     => $globalHdp,
            'total_hens'     => $totalHens,
            'batch_reports'  => $batchReports,
            'irregularities' => $irregularities,
            'laying_batches' => $layingBatches->count(),
        ];
    }

    /**
     * Construit le bloc WhatsApp pour le résumé quotidien.
     */
    public function buildWhatsAppBlock(): string
    {
        $report = $this->getDailyReport();

        if (! $report['has_layers']) {
            return ""; // Pas de pondeuses actives
        }

        $lines = [];
        $lines[] = "🥚 *PRODUCTION ŒUFS*";
        $lines[] = "  Total collecté : *{$report['total_eggs']}* œufs";
        $lines[] = "  HDP global : *{$report['global_hdp']}%* ({$report['total_hens']} poules)";

        if ($report['total_broken'] > 0 || $report['total_anomalies'] > 0) {
            $lines[] = "  Cassés : {$report['total_broken']} | Anomalies : {$report['total_anomalies']}";
        }

        // Détail par lot
        $lines[] = "";
        foreach ($report['batch_reports'] as $br) {
            $batch = $br['batch'];
            if ($br['status'] === 'missing') {
                $lines[] = "  ❓ {$batch->code} ({$batch->building->name}) — *PAS DE COLLECTE*";
            } else {
                $trend = '';
                if ($br['prev_hdp'] !== null) {
                    $diff = round($br['hdp'] - $br['prev_hdp'], 1);
                    if ($diff > 0) $trend = " ↑+{$diff}%";
                    elseif ($diff < 0) $trend = " ↓{$diff}%";
                }
                $emoji = $br['hdp'] >= 80 ? '✅' : ($br['hdp'] >= 60 ? '⚠️' : '🔴');
                $lines[] = "  {$emoji} {$batch->code} : *{$br['eggs']}* œufs — HDP *{$br['hdp']}%*{$trend}";
            }
        }

        // Irrégularités
        if (count($report['irregularities']) > 0) {
            $lines[] = "";
            $lines[] = "  ⚠️ *ANOMALIES DÉTECTÉES :*";
            foreach ($report['irregularities'] as $ir) {
                $lines[] = "  {$ir['emoji']} [{$ir['batch']}] {$ir['message']}";
            }
        }

        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Récupère la norme HDP pour un modèle et un âge donnés.
     */
    private function getNormHdp(?string $modelName, ?int $ageWeeks): ?float
    {
        if (! $modelName || ! $ageWeeks) return null;

        $norm = ProductionNorm::where('model_name', 'LIKE', "%{$modelName}%")
            ->where('batch_type', 'ponte')
            ->where('week_number', $ageWeeks)
            ->first();

        return $norm?->target_laying_rate;
    }
}
