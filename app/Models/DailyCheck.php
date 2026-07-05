<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\BelongsToFarm;

/**
 * Model DailyCheck — Hotfix mortalité (v2).
 *
 * CORRECTION : Remplacement de $check->_pendingImpactDiff (propriété
 * dynamique Eloquent → injectée dans la requête SQL UPDATE → erreur)
 * par un tableau statique PHP (private static array $pendingDiffs).
 *
 * Un tableau statique de classe n'est JAMAIS sérialisé par Eloquent,
 * donc jamais inclus dans les colonnes de la requête SQL.
 */
class DailyCheck extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    /**
     * Stockage inter-événements du diff d'impact.
     * Tableau statique PHP — invisible pour Eloquent.
     * Clé = ID du check, valeur = diff à appliquer sur current_quantity.
     */
    private static array $pendingDiffs = [];

    protected $fillable = [
        'farm_id', 'batch_id', 'check_date',
        'mortality', 'feed_consumed', 'feed_type', 'feed_unit_cost', 'water_consumed',
        'temp_min', 'temp_max', 'temp_source', 'temp_recorded_by', 'humidity',
        'avg_weight', 'uniformity_pct', 'weight_samples', 'health_status',
        'treatment_type', 'treatment_name',
        'qty_quarantine_in', 'qty_quarantine_out', 'qty_sorted_out',
        'observations', 'litter_changed', 'manure_collected_kg',
        'lame_count', 'pecking_injury_count',
    ];

    protected $casts = [
        'check_date'         => 'date',
        'feed_consumed'      => 'decimal:2',
        'feed_unit_cost'     => 'decimal:2',
        'water_consumed'     => 'decimal:2',
        'avg_weight'         => 'decimal:3',
        'uniformity_pct'     => 'decimal:2',
        'weight_samples'     => 'array',
        'temp_min'           => 'decimal:1',
        'temp_max'           => 'decimal:1',
        'humidity'           => 'decimal:1',
        'litter_changed'     => 'boolean',
        'manure_collected_kg' => 'decimal:2',
        'mortality'          => 'integer',
        'qty_quarantine_in'  => 'integer',
        'qty_quarantine_out' => 'integer',
        'qty_sorted_out'     => 'integer',
        'lame_count'         => 'integer',
        'pecking_injury_count' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function extension(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\DailyCheckExtension::class);
    }

    /**
     * Calcule poids moyen et TAUX D'UNIFORMITÉ depuis les pesées individuelles
     * de l'échantillon (kg) — CÔTÉ SERVEUR, source de vérité (la valeur
     * calculée par le navigateur n'est jamais crue sur parole).
     *
     * FORMULE (guides de souche) :
     *   uniformité (%) = 100 × (nb de pesées dans [0,9 × m̄ ; 1,1 × m̄]) / n
     *   avec m̄ = moyenne arithmétique de l'échantillon.
     *
     * Retourne null si aucune pesée exploitable ; l'uniformité n'est calculée
     * qu'à partir de 2 pesées (sur 1 seul sujet elle vaudrait trivialement
     * 100 % — sans signification).
     *
     * @param  array $samples  Poids en kg (valeurs non numériques ignorées)
     * @return array{samples: float[], count: int, avg_weight: float, uniformity_pct: ?float}|null
     */
    public static function computeSampleStats(array $samples): ?array
    {
        $clean = collect($samples)
            ->filter(fn ($v) => is_numeric($v) && (float) $v > 0)
            ->map(fn ($v) => round((float) $v, 3))
            ->values();

        if ($clean->isEmpty()) {
            return null;
        }

        $avg = $clean->avg();
        $uniformity = null;

        if ($clean->count() >= 2 && $avg > 0) {
            $inRange = $clean->filter(fn (float $w) => $w >= $avg * 0.9 && $w <= $avg * 1.1)->count();
            $uniformity = round($inRange / $clean->count() * 100, 2);
        }

        return [
            'samples'        => $clean->all(),
            'count'          => $clean->count(),
            'avg_weight'     => round($avg, 3),
            'uniformity_pct' => $uniformity,
        ];
    }

    public function calculateNetImpact(): int
    {
        return (
            (int) $this->mortality
            + (int) $this->qty_quarantine_in
            + (int) $this->qty_sorted_out
        ) - (int) $this->qty_quarantine_out;
    }

    protected static function booted(): void
    {
        // Création → déduire l'impact
        static::created(function (DailyCheck $check) {
            $impact = $check->calculateNetImpact();
            if ($impact !== 0) {
                static::applyBatchImpact($check->batch_id, -$impact);
            }
            static::autoCompleteTasks($check);
            static::checkDailyMortalitySpike($check);
        });

        // Avant update → calculer le diff et le stocker dans le tableau statique
        static::updating(function (DailyCheck $check) {
            $oldImpact = (
                (int) $check->getOriginal('mortality')
                + (int) $check->getOriginal('qty_quarantine_in')
                + (int) $check->getOriginal('qty_sorted_out')
            ) - (int) $check->getOriginal('qty_quarantine_out');

            $newImpact = $check->calculateNetImpact();
            $diff = $newImpact - $oldImpact;

            if ($diff !== 0) {
                // Clé = ID du check, stocké dans le tableau statique PHP
                // JAMAIS dans les attributs Eloquent → jamais dans le SQL
                static::$pendingDiffs[$check->id] = $diff;
            }
        });

        // Après update → appliquer le diff stocké
        static::updated(function (DailyCheck $check) {
            $diff = static::$pendingDiffs[$check->id] ?? 0;
            if ($diff !== 0) {
                static::applyBatchImpact($check->batch_id, -$diff);
                unset(static::$pendingDiffs[$check->id]);
            }
        });

        // Suppression → restituer l'impact
        static::deleted(function (DailyCheck $check) {
            $impact = $check->calculateNetImpact();
            if ($impact !== 0) {
                static::applyBatchImpact($check->batch_id, $impact);
            }
        });
    }

    /**
     * Applique un delta atomique sur current_quantity du lot.
     * Utilise lockForUpdate + UPDATE direct SQL pour éviter les boucles d'observer.
     */
    private static function applyBatchImpact(int $batchId, int $delta): void
    {
        if ($delta === 0) return;

        DB::transaction(function () use ($batchId, $delta) {
            $batch = \App\Models\Batch::lockForUpdate()->find($batchId);
            if (! $batch) {
                Log::error("[DailyCheck] Lot #{$batchId} introuvable.");
                return;
            }

            $newQty = max(0, $batch->current_quantity + $delta);

            if ($batch->current_quantity + $delta < 0) {
                Log::warning("[DailyCheck] Effectif négatif bloqué sur lot {$batch->code} (delta: {$delta}).");
            }

            // UPDATE direct : n'émet pas d'événements Eloquent → pas de boucle
            DB::table('batches')
                ->where('id', $batchId)
                ->update(['current_quantity' => $newQty, 'updated_at' => now()]);
        });
    }

    /**
     * Auto-complète les tâches générées de catégorie "controle" (relevé
     * mortalité, contrôle eau, relevé température...) planifiées le même
     * jour pour le bâtiment du lot : le pointage journalier couvre déjà
     * ces relevés, inutile de les saisir deux fois.
     */
    private static function autoCompleteTasks(DailyCheck $check): void
    {
        $buildingId = $check->batch?->building_id;
        if (! $buildingId) return;

        \App\Models\TaskAssignment::where('building_id', $buildingId)
            ->where('scheduled_date', $check->check_date->toDateString())
            ->where('category', 'controle')
            ->whereIn('status', ['a_faire', 'en_retard'])
            ->get()
            ->each(function (\App\Models\TaskAssignment $task) use ($check) {
                $task->update([
                    'status'           => 'fait',
                    'completed_at'     => now(),
                    'completed_by'     => \Illuminate\Support\Facades\Auth::id(),
                    'completion_notes' => "Auto-complétée via le pointage journalier #{$check->id}.",
                ]);
            });
    }

    public function getWaterFeedRatioAttribute(): float
    {
        if ((float) $this->feed_consumed <= 0) return 0;
        return round((float) $this->water_consumed / (float) $this->feed_consumed, 2);
    }

    public function getAvgTemperatureAttribute(): ?float
    {
        if ($this->temp_min === null || $this->temp_max === null) return null;
        return round(((float) $this->temp_min + (float) $this->temp_max) / 2, 1);
    }

    /**
     * Détecte un PIC de mortalité QUOTIDIEN anormal et alerte (par bâtiment) —
     * early-warning maladie, complémentaire de l'alerte de mortalité CUMULÉE
     * (5 %, qui arrive trop tard). Garde-fou double (cf. paramètres
     * elevage.daily_mortality_alert_min/pct) : on exige un MINIMUM de morts en
     * valeur absolue ET un taux quotidien élevé, pour ne pas alerter sur un
     * décès isolé. L'envoi est isolé (rescue) : une panne de notification ne
     * casse jamais la saisie du pointage.
     */
    protected static function checkDailyMortalitySpike(self $check): void
    {
        $deaths = (int) $check->mortality;
        if ($deaths <= 0) {
            return;
        }

        $minDeaths = (int) setting('elevage.daily_mortality_alert_min', 3);
        if ($deaths < $minDeaths) {
            return;
        }

        $batch = Batch::with('building')->find($check->batch_id);
        if (! $batch || $batch->status !== Batch::STATUS_ACTIF) {
            return;
        }

        // Effectif AVANT le pointage du jour = effectif courant + impact net appliqué.
        $effectifBefore = max(1, (int) $batch->current_quantity + $check->calculateNetImpact());
        $dailyRate = round($deaths / $effectifBefore * 100, 2);

        if ($dailyRate < (float) setting('elevage.daily_mortality_alert_pct', 0.5)) {
            return;
        }

        rescue(
            fn () => app(\App\Services\NotificationHub::class)->alertDailyMortalitySpike($batch, $deaths, $dailyRate),
            fn ($e) => \Illuminate\Support\Facades\Log::warning("Alerte pic mortalité non émise (pointage #{$check->id}) : {$e->getMessage()}"),
            report: false
        );
    }
}
