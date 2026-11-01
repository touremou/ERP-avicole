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

    public const SEVERITIES = [self::SEVERITY_MINOR, self::SEVERITY_MODERATE, self::SEVERITY_CRITICAL];

    /**
     * CE QU'EXIGE LA DÉCLARATION D'UN INCIDENT — règle commune au bureau et au
     * terrain (décision de l'exploitation).
     *
     * Le bureau exigeait au moins un mort ; le terrain acceptait zéro. Or un
     * incident n'en provoque pas toujours : symptômes respiratoires, chute
     * d'appétit, coup de chaleur, blessures collectives, traitement préventif.
     * La mortalité devient donc facultative (zéro par défaut), et la gravité et
     * l'ACTION PRISE (isolement, traitement administré, délai d'attente…)
     * deviennent obligatoires, des deux côtés.
     *
     * Les champs propres à chaque porte (lot, symptômes, date) restent déclarés
     * par elle.
     */
    public static function reglesDeDeclaration(): array
    {
        return [
            'mortality_count' => 'nullable|integer|min:0',
            'severity'        => 'required|in:' . implode(',', self::SEVERITIES),
            'action_taken'    => 'required|string|max:1000',
        ];
    }

    protected $fillable = [
        'uuid', 'farm_id', 'building_id', 'batch_id', 'daily_check_id', 'user_id', 'incident_date', 'mortality_count',
        'symptoms', 'action_taken', 'photo_path', 'status', 'severity', 'suspected_disease', 'vet_prescription',
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
    public function building(): BelongsTo { return $this->belongsTo(Building::class)->withTrashed(); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class)->withTrashed(); }
    public function dailyCheck(): BelongsTo { return $this->belongsTo(DailyCheck::class)->withTrashed(); } // pointage d'origine
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
