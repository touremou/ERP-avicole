<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

/**
 * HealthIncident — incident sanitaire d'élevage (anomalie / crise) déclaré sur
 * le terrain, diagnostiqué par le vétérinaire, puis traité et résolu.
 *
 * Workflow : en_attente → diagnostique → resolu (ou clôture rapide non médicale).
 * Gravité (mineur/modere/critique) indépendante du statut ; pilote l'alerte et
 * la priorisation. Traçabilité complète : déclarant, diagnostiqueur, résolveur,
 * coût de traitement, quarantaine.
 */
class HealthIncident extends Model
{
    use BelongsToFarm;

    public const STATUS_PENDING   = 'en_attente';
    public const STATUS_DIAGNOSED = 'diagnostique';
    public const STATUS_RESOLVED  = 'resolu';

    public const SEVERITY_MINOR    = 'mineur';
    public const SEVERITY_MODERATE = 'modere';
    public const SEVERITY_CRITICAL = 'critique';

    protected $fillable = [
        'farm_id', 'building_id', 'batch_id', 'daily_check_id', 'user_id', 'incident_date', 'mortality_count',
        'symptoms', 'photo_path', 'status', 'severity', 'suspected_disease', 'vet_prescription',
        'diagnosed_by', 'diagnosed_at', 'treatment_cost',
        'resolved_by', 'resolved_at', 'resolution_notes',
        'is_quarantined', 'quarantine_started_at', 'quarantine_ended_at',
    ];

    protected $casts = [
        'incident_date'         => 'date',
        'diagnosed_at'          => 'datetime',
        'resolved_at'           => 'datetime',
        'quarantine_started_at' => 'datetime',
        'quarantine_ended_at'   => 'datetime',
        'is_quarantined'        => 'boolean',
        'treatment_cost'        => 'decimal:2',
    ];

    // ─── RELATIONS ───
    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function dailyCheck(): BelongsTo { return $this->belongsTo(DailyCheck::class); } // pointage d'origine
    public function user(): BelongsTo { return $this->belongsTo(User::class); }            // déclarant
    public function diagnosedBy(): BelongsTo { return $this->belongsTo(User::class, 'diagnosed_by'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }

    // ─── SCOPES ───
    public function scopePending($q) { return $q->where('status', self::STATUS_PENDING); }
    public function scopeOpen($q) { return $q->where('status', '!=', self::STATUS_RESOLVED); }
    public function scopeCritical($q) { return $q->where('severity', self::SEVERITY_CRITICAL); }

    // ─── ÉTAT ───
    public function isResolved(): bool { return $this->status === self::STATUS_RESOLVED; }

    // ─── ACCESSEURS ───

    /** Libellé lisible de la gravité. */
    public function getSeverityLabelAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_MINOR    => 'Mineur',
            self::SEVERITY_CRITICAL => 'Critique',
            default                 => 'Modéré',
        };
    }

    /** Couleur Tailwind associée à la gravité (badges). */
    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_MINOR    => 'amber',
            self::SEVERITY_CRITICAL => 'rose',
            default                 => 'orange',
        };
    }

    /** Jours depuis la déclaration jusqu'à la résolution (ou aujourd'hui si ouvert). */
    public function getDaysOpenAttribute(): int
    {
        if (! $this->incident_date) {
            return 0;
        }
        $end = $this->resolved_at ?? now();

        return (int) $this->incident_date->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());
    }

    /**
     * Diagnostic en retard : toujours en attente au-delà du délai paramétré
     * (elevage.incident_diagnosis_sla_days, défaut 2 jours).
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->days_open >= (int) setting('elevage.incident_diagnosis_sla_days', 2);
    }
}
