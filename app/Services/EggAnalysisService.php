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

        /*
         * LES LOTS EN ÂGE DE PONDRE — et eux seuls.
         *
         * Cette sélection prenait TOUT lot de type « ponte », sans regarder son
         * âge. Or une poulette n'entre en ponte qu'à 18 semaines environ
         * (Batch::minLayingAgeDays(), déduit de la courbe de la souche). Un lot
         * de 6 semaines est bien un lot de ponte : il ne pond pas pour autant.
         *
         * Conséquence, chaque matin, sur un élevage qui démarre son cheptel :
         *
         *   • « PAS DE COLLECTE » pour chaque lot trop jeune — alors que
         *     l'application REFUSE d'enregistrer une collecte sur ces lots
         *     (RecordEggCollection et StoreEggProductionRequest lisent tous deux
         *     `minLayingAgeDays`). Le résumé reprochait de n'avoir pas fait ce
         *     que le formulaire interdit de faire ;
         *   • « HDP global : 0 % (1 491 poules) » — 1 491 sujets comptés comme
         *     poules alors qu'aucun n'est en âge de pondre. Annoncer 0 % dit
         *     « vos poules ont cessé de pondre » ; la vérité est « vous n'avez
         *     pas encore de pondeuses » ;
         *   • « Production totale (0 œufs) < 70 % de la moyenne 7j », en
         *     sévérité CRITIQUE — une alerte sur un effondrement impossible.
         *
         * Trois fausses alertes par jour, sur le message que le promoteur lit
         * depuis l'étranger. Une alerte critique quotidienne et fausse ne coûte
         * pas seulement de l'attention : elle apprend à ne plus lire les autres.
         *
         * `canCollectEggs()` est la MÊME déclaration que celle qu'applique la
         * saisie — « garde-fou partagé par la validation de collecte et la vue »,
         * dit son commentaire. Le résumé était le seul écran à ne pas la lire.
         *
         * Un lot qui a ATTEINT l'âge de pondre et ne rend rien reste signalé :
         * là, l'absence de collecte est une vraie anomalie.
         *
         * ─── ET UN LOT QUI A DES ŒUFS RESTE COMPTÉ, QUEL QUE SOIT SON ÂGE ───
         *
         * Le filtre sur le seul âge AURAIT MASQUÉ des œufs réellement
         * enregistrés : une collecte saisie avant que ce garde-fou n'existe, une
         * reprise de données, une souche précoce dont la norme n'est pas encore
         * renseignée. Faire disparaître d'un rapport une production consignée
         * serait un défaut PIRE que celui qu'on corrige — le premier fait du
         * bruit, le second efface.
         *
         * La règle est donc : en âge de pondre, OU ayant réellement pondu. On ne
         * se tait que sur les lots dont l'application elle-même refuse la
         * collecte ET qui n'ont rien rendu.
         */
        $collectesDuJour = EggProduction::whereDate('production_date', $yesterday)
            ->pluck('batch_id')
            ->all();

        $layingBatches = Batch::active()
            ->byType('ponte')
            ->with('building')
            ->get()
            ->filter(fn (Batch $batch) => $batch->canCollectEggs()
                || in_array($batch->id, $collectesDuJour, true))
            ->values();

        if ($layingBatches->isEmpty()) {
            return [
                'has_layers'    => false,
                'collections'   => collect(),
                'irregularities' => [],
                'summary'       => null,
            ];
        }

        // Collectes d'hier
        $yesterdayCollections = EggProduction::whereDate('production_date', $yesterday)
            ->with('batch.building')
            ->get();

        // Collectes avant-hier (pour comparaison)
        $dayBeforeCollections = EggProduction::whereDate('production_date', $dayBefore)->get();

        // Analyse
        $irregularities = [];
        $totalEggs = 0;
        $totalBroken = 0;
        $totalAnomalies = 0;
        $batchReports = [];

        foreach ($layingBatches as $batch) {
            $collection = $yesterdayCollections->where('batch_id', $batch->id)->first();
            $prevCollection = $dayBeforeCollections->where('batch_id', $batch->id)->first();

            // Âge en semaines — la courbe de ponte (Lohmann, Hy-Line) est indexée
            // là-dessus. Il se comptait depuis l'ARRIVÉE, et sans le « + 1 » de
            // l'accesseur : des poulettes reçues en âge de pondre étaient jugées
            // contre la semaine 1 de leur propre courbe.
            $ageWeeks = $batch->arrival_date ? (int) floor($batch->age / 7) : null;

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

            // Calcul HDP (sur l'effectif vivant courant)
            $totalCollected = (float) ($collection->total_eggs_collected ?? 0);
            $hens = (int) $batch->current_quantity;
            $hdp = ($hens > 0) ? round(($totalCollected / $hens) * 100, 1) : 0;

            // HDP de la veille
            $prevTotal = $prevCollection ? (float) ($prevCollection->total_eggs_collected ?? 0) : null;
            $prevHdp = ($prevTotal !== null && $hens > 0) ? round(($prevTotal / $hens) * 100, 1) : null;

            // Œufs cassés (le modèle ne trace pas d'« anomalies » distinctes).
            $broken = (float) ($collection->broken_eggs ?? 0);
            $anomalies = 0.0;
            $brokenRate = ($totalCollected > 0) ? round((($broken + $anomalies) / $totalCollected) * 100, 1) : 0;

            // Calibres (petits œufs).
            $qtyS = (float) ($collection->small_eggs ?? 0);
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
        $avg7days = EggProduction::where('production_date', '>=', now()->subDays(8))
            ->where('production_date', '<', $yesterday)
            ->avg('total_eggs_collected');

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
