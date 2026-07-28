<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\ReferencesEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignment extends Model
{
    use BelongsToFarm, ReferencesEmployee;

    /** Délai au-delà duquel une prise (claim) non clôturée est libérée. */
    public const CLAIM_TIMEOUT_MINUTES = 120;

    protected $fillable = [
        'uuid',
        'farm_id', 'task_template_id', 'employee_id', 'title', 'description',
        'category', 'building_id', 'plot_id', 'batch_id',
        'crop_cycle_id', 'crop_protocol_item_id', 'stored_lot_id',
        'scheduled_date', 'scheduled_time',
        'duration_minutes', 'priority', 'status', 'started_at', 'claimed_by', 'completed_at',
        'completed_by', 'completion_notes', 'is_auto_generated', 'is_pool',
        'proof_type', 'proof_label', 'proof_unit', 'proof_photo_path', 'proof_value',
    ];

    protected $casts = [
        'scheduled_date'   => 'date',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'is_auto_generated' => 'boolean',
        'is_pool'          => 'boolean',
        'proof_value'      => 'decimal:2',
    ];

    /** La tâche exige-t-elle une preuve à la complétion ? */
    public function requiresProof(): bool
    {
        return in_array($this->proof_type, ['photo', 'valeur'], true);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    /** Étape d'itinéraire technique dont cette tâche est la matérialisation (S1). */
    public function protocolItem(): BelongsTo
    {
        return $this->belongsTo(CropProtocolItem::class, 'crop_protocol_item_id');
    }

    /** Lot en conservation dont cette tâche est le contrôle périodique (T2). */
    public function storedLot(): BelongsTo
    {
        return $this->belongsTo(StoredLot::class);
    }

    /**
     * BOUCLE DE RETOUR (S1) : cocher la tâche valide l'étape d'itinéraire.
     *
     * Sans cela, les deux vues se contrediraient — le calendrier dirait « fait »
     * et l'itinéraire technique continuerait d'afficher « en retard », puisque
     * CropProtocolAlertService devine l'exécution par rapprochement de NOMS
     * (intrant ou événement dont le libellé ressemble à l'étape). Une validation
     * explicite est fiable là où l'inférence est fragile ; c'est d'ailleurs
     * l'ordre de priorité que ce service applique déjà.
     *
     * Appelée depuis les DEUX portes d'entrée (web TaskController::complete et
     * sync SyncService::taskComplete). Idempotente : firstOrCreate sur
     * (cycle, étape).
     */
    public function recordProtocolCompletion(?int $userId = null, ?string $notes = null): void
    {
        if (! $this->crop_cycle_id || ! $this->crop_protocol_item_id) {
            return;
        }

        CropProtocolCompletion::firstOrCreate(
            [
                'crop_cycle_id'         => $this->crop_cycle_id,
                'crop_protocol_item_id' => $this->crop_protocol_item_id,
            ],
            [
                'farm_id'      => $this->farm_id,
                'completed_at' => $this->completed_at ?? now(),
                'completed_by' => $userId,
                // La valeur de preuve chiffrée (pieds atteints à l'observation)
                // vaut mieux que rien dans le registre de l'itinéraire.
                'quantity'     => $this->proof_value,
                'unit'         => $this->proof_unit,
                'notes'        => $notes ?: $this->completion_notes,
            ],
        );
    }

    public function claimant(): BelongsTo { return $this->belongsTo(User::class, 'claimed_by'); }

    /** Une prise trop ancienne (timeout) est considérée expirée → libérable. */
    public function isClaimStale(): bool
    {
        if (! $this->started_at) return true;

        return $this->started_at->lt(now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
    }

    /** Prise ACTIVE (en cours, non expirée) par un AUTRE utilisateur que $userId. */
    public function isClaimedByOther(?int $userId): bool
    {
        return $this->status === 'en_cours'
            && $this->claimed_by !== null
            && $this->claimed_by !== $userId
            && ! $this->isClaimStale();
    }

    /**
     * Trace un événement de CYCLE DE VIE de la tâche (prise / libération /
     * complétion) au journal d'audit — « qui a fait quoi, quand ». On journalise
     * explicitement ces transitions métier (et NON toutes les écritures : la
     * génération quotidienne en masse n'a pas sa place dans un journal lisible).
     * L'auteur (causer) est l'utilisateur authentifié.
     */
    public function logLifecycle(string $event, array $attributes = []): void
    {
        activity('audit')
            ->performedOn($this)
            ->causedBy(\Illuminate\Support\Facades\Auth::user())
            ->event($event)
            ->withProperties(['attributes' => $attributes])
            ->log("task.{$event}");
    }

    public function template(): BelongsTo { return $this->belongsTo(TaskTemplate::class, 'task_template_id'); }
    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
    public function plot(): BelongsTo { return $this->belongsTo(\App\Models\Plot::class); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function completedByUser(): BelongsTo { return $this->belongsTo(User::class, 'completed_by'); }

    // Scopes
    public function scopeForDate($q, $date) { return $q->where('scheduled_date', $date); }
    public function scopePending($q) { return $q->whereIn('status', ['a_faire', 'en_retard']); }
    public function scopeCompleted($q) { return $q->where('status', 'fait'); }
    public function scopeOverdue($q) { return $q->where('status', 'a_faire')->where('scheduled_date', '<', now()->toDateString()); }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'a_faire' && $this->scheduled_date->lt(now()->startOfDay());
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'a_faire'   => '⏳ À faire',
            'en_cours'  => '🔄 En cours',
            'fait'      => '✅ Fait',
            'annule'    => '❌ Annulé',
            'en_retard' => '🔴 En retard',
            default     => $this->status,
        };
    }
}
