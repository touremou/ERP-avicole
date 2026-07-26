<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * LOT DE CONSERVATION — la décision de garder une marchandise pour la vendre
 * plus cher plus tard (T2).
 *
 * Ce n'est pas une ligne de stock de plus : c'est la formalisation d'un PARI.
 * Un pari se juge sur trois éléments, tous portés ici :
 *
 *  - un PRIX-CIBLE, sans quoi « plus tard » n'a pas de critère d'arrêt ;
 *  - une DATE BUTOIR, sans quoi le stock se garde par inertie jusqu'au rebut ;
 *  - un COÛT DE REVIENT FIGÉ à l'ouverture, car le CMP de la ligne d'inventaire
 *    bougera avec les entrées suivantes alors que la rentabilité de CETTE
 *    décision se juge sur la matière engagée ce jour-là.
 *
 * Jamais créé automatiquement : conserver est un acte de gestion.
 */
class StoredLot extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    public const STATUS_EN_STOCK    = 'en_stock';
    public const STATUS_VENDU       = 'vendu';
    public const STATUS_PERTE       = 'perte_totale';
    public const STATUS_CLOTURE     = 'cloture';

    public const STATUSES = [
        self::STATUS_EN_STOCK => 'En conservation',
        self::STATUS_VENDU    => 'Vendu',
        self::STATUS_PERTE    => 'Perte totale',
        self::STATUS_CLOTURE  => 'Clôturé',
    ];

    protected $fillable = [
        'uuid', 'farm_id', 'harvest_id', 'crop_transformation_id', 'stock_id',
        'label', 'quantity_initial', 'quantity_current', 'unit',
        'unit_cost', 'target_unit_price', 'last_market_price',
        'opened_at', 'hold_until', 'check_interval_days',
        'status', 'closed_at', 'closed_reason', 'notes',
    ];

    protected $casts = [
        'quantity_initial'    => 'decimal:3',
        'quantity_current'    => 'decimal:3',
        'unit_cost'           => 'decimal:2',
        'target_unit_price'   => 'decimal:2',
        'last_market_price'   => 'decimal:2',
        'opened_at'           => 'date',
        'hold_until'          => 'date',
        'closed_at'           => 'date',
        'check_interval_days' => 'integer',
    ];

    // ─── RELATIONS ───

    public function stock(): BelongsTo { return $this->belongsTo(Stock::class); }
    public function harvest(): BelongsTo { return $this->belongsTo(Harvest::class); }
    public function transformation(): BelongsTo { return $this->belongsTo(CropTransformation::class, 'crop_transformation_id'); }

    public function checks(): HasMany
    {
        return $this->hasMany(StoredLotCheck::class)->orderByDesc('checked_at');
    }

    // ─── SCOPES ───

    /** Lots encore sous conservation (les seuls qui appellent une action). */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_EN_STOCK);
    }

    /** Lots dont l'échéance de détention tombe dans les :days prochains jours. */
    public function scopeDeadlineWithin($query, int $days)
    {
        return $query->open()
            ->whereNotNull('hold_until')
            ->whereDate('hold_until', '<=', now()->addDays($days)->toDateString());
    }

    // ─── ACCESSEURS : ÉTAT DE LA CONSERVATION ───

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->status === self::STATUS_EN_STOCK;
    }

    /** Jours écoulés depuis la mise en conservation. */
    public function getDaysHeldAttribute(): int
    {
        return (int) Carbon::parse($this->opened_at)->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * Jours restants avant l'échéance. Négatif = échéance DÉPASSÉE, null = aucune
     * échéance fixée (le cas qu'on cherche justement à éviter).
     */
    public function getDaysToDeadlineAttribute(): ?int
    {
        if (! $this->hold_until) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(Carbon::parse($this->hold_until)->startOfDay(), false);
    }

    public function getIsPastDeadlineAttribute(): bool
    {
        return $this->is_open && $this->days_to_deadline !== null && $this->days_to_deadline < 0;
    }

    /** Date du dernier contrôle effectif (null = jamais contrôlé). */
    public function getLastCheckedAtAttribute(): ?Carbon
    {
        $value = $this->relationLoaded('checks')
            ? $this->checks->max('checked_at')
            : $this->checks()->max('checked_at');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Prochaine échéance de contrôle. Sans contrôle antérieur, elle part de la
     * date d'ouverture : un lot jamais contrôlé est en retard dès le premier
     * intervalle écoulé, il ne bénéficie pas d'un sursis silencieux.
     */
    public function getNextCheckDueAtAttribute(): Carbon
    {
        $base = $this->last_checked_at ?? Carbon::parse($this->opened_at);

        return $base->copy()->startOfDay()->addDays(max(1, (int) $this->check_interval_days));
    }

    public function getCheckIsOverdueAttribute(): bool
    {
        return $this->is_open && $this->next_check_due_at->startOfDay()->lte(now()->startOfDay());
    }

    // ─── ACCESSEURS : FREINTE ───

    /** Perte cumulée depuis l'ouverture (kg). */
    public function getTotalShrinkageAttribute(): float
    {
        return round((float) $this->quantity_initial - (float) $this->quantity_current, 3);
    }

    public function getShrinkagePercentAttribute(): ?float
    {
        $initial = (float) $this->quantity_initial;

        return $initial > 0 ? round($this->total_shrinkage / $initial * 100, 2) : null;
    }

    // ─── ACCESSEURS : ÉCONOMIE DU PARI ───

    /** Valeur de ce qui reste, AU COÛT (ce qui est immobilisé). */
    public function getValueAtCostAttribute(): float
    {
        return round((float) $this->quantity_current * (float) ($this->unit_cost ?? 0), 2);
    }

    /**
     * L'objectif de prix est-il atteint, d'après le DERNIER cours constaté ?
     * null si aucun cours n'a encore été relevé ou aucun objectif fixé — on ne
     * conclut pas sans donnée.
     */
    public function getTargetReachedAttribute(): ?bool
    {
        if ($this->target_unit_price === null || $this->last_market_price === null) {
            return null;
        }

        return (float) $this->last_market_price >= (float) $this->target_unit_price;
    }

    /**
     * Marge que dégagerait une vente AU DERNIER COURS CONSTATÉ, freinte déjà
     * déduite (elle porte sur quantity_current).
     *
     * C'est le seul chiffre qui répond à « est-ce que ce pari est encore gagnant
     * aujourd'hui ? » : un cours qui monte ne compense pas forcément 15 % de
     * freinte.
     */
    public function getMarginAtMarketAttribute(): ?float
    {
        if ($this->last_market_price === null) {
            return null;
        }

        $revenue = (float) $this->quantity_current * (float) $this->last_market_price;

        return round($revenue - ((float) $this->quantity_initial * (float) ($this->unit_cost ?? 0)), 2);
    }

    /**
     * Alertes du lot, hiérarchisées — consommées par la page de conservation et
     * le générateur de tâches.
     *
     * @return array<int, array{severity: string, code: string, message: string}>
     */
    public function alerts(): array
    {
        if (! $this->is_open) {
            return [];
        }

        $alerts = [];

        if ($this->is_past_deadline) {
            $alerts[] = [
                'severity' => 'critique',
                'code'     => 'deadline_passed',
                'message'  => sprintf(
                    'Échéance de détention dépassée depuis %d jour(s) : vendre ou déclasser.',
                    abs((int) $this->days_to_deadline),
                ),
            ];
        } elseif ($this->days_to_deadline !== null && $this->days_to_deadline <= 14) {
            $alerts[] = [
                'severity' => 'attention',
                'code'     => 'deadline_near',
                'message'  => sprintf('Échéance de détention dans %d jour(s).', (int) $this->days_to_deadline),
            ];
        }

        if ($this->hold_until === null) {
            // Absence d'échéance : c'est la dérive elle-même qu'on signale.
            $alerts[] = [
                'severity' => 'attention',
                'code'     => 'no_deadline',
                'message'  => 'Aucune échéance de détention fixée — le lot peut se garder indéfiniment.',
            ];
        }

        if ($this->check_is_overdue) {
            $alerts[] = [
                'severity' => 'attention',
                'code'     => 'check_overdue',
                'message'  => $this->last_checked_at
                    ? sprintf('Contrôle en retard (dernier le %s).', $this->last_checked_at->format('d/m/Y'))
                    : 'Jamais contrôlé depuis la mise en conservation.',
            ];
        }

        if ($this->target_reached === true) {
            $alerts[] = [
                'severity' => 'info',
                'code'     => 'target_reached',
                'message'  => sprintf(
                    'Prix-cible atteint (cours constaté %s ≥ objectif %s) : c\'est le moment de vendre.',
                    number_format((float) $this->last_market_price, 0, ',', ' '),
                    number_format((float) $this->target_unit_price, 0, ',', ' '),
                ),
            ];
        }

        if ($this->shrinkage_percent !== null && $this->shrinkage_percent >= 10) {
            $alerts[] = [
                'severity' => 'critique',
                'code'     => 'heavy_shrinkage',
                'message'  => sprintf(
                    'Freinte de %s %% depuis l\'ouverture : la conservation détruit la marge.',
                    number_format($this->shrinkage_percent, 1, ',', ' '),
                ),
            ];
        }

        return $alerts;
    }
}
