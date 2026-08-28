<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campagne saisonnière (Tabaski, Ramadan, fêtes...).
 *
 * Agrège des lots vers un objectif commercial daté et fournit les KPI de
 * pilotage : effectifs, coûts (acquisition + aliment + santé), chiffre
 * d'affaires réalisé sur les ventes des lots liés, marge projetée vs
 * réalisée, et compte à rebours jusqu'au pic de vente.
 */
class Campaign extends Model
{
    use SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id', 'name', 'type', 'target_family', 'status',
        'start_date', 'target_date',
        'target_head_count', 'target_budget', 'target_sale_price',
        'notes',
    ];

    protected $casts = [
        'start_date'        => 'date',
        'target_date'       => 'date',
        'target_head_count' => 'integer',
        'target_budget'     => 'decimal:2',
        'target_sale_price' => 'decimal:2',
    ];

    public const TYPES = [
        'tabaski' => 'Tabaski / Eid al-Adha',
        'ramadan' => 'Ramadan',
        'fetes'   => 'Fêtes de fin d\'année',
        'autre'   => 'Autre',
    ];

    public const STATUSES = [
        'preparation'   => 'Préparation',
        'engraissement' => 'Engraissement',
        'vente'         => 'Vente',
        'cloturee'      => 'Clôturée',
    ];

    // ═══════════════════════════════════════════════
    // RELATIONS
    // ═══════════════════════════════════════════════

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    // ═══════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cloturee');
    }

    // ═══════════════════════════════════════════════
    // ACCESSEURS — LIBELLÉS
    // ═══════════════════════════════════════════════

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'preparation'   => 'slate',
            'engraissement' => 'amber',
            'vente'         => 'emerald',
            'cloturee'      => 'blue',
            default         => 'slate',
        };
    }

    // ═══════════════════════════════════════════════
    // ACCESSEURS — COMPTE À REBOURS
    // ═══════════════════════════════════════════════

    /** Jours restants jusqu'au pic (négatif si passé). */
    public function getDaysUntilTargetAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays(Carbon::parse($this->target_date)->startOfDay(), false);
    }

    public function getIsUrgentAttribute(): bool
    {
        $d = $this->days_until_target;
        return $d >= 0 && $d <= 30;
    }

    // ═══════════════════════════════════════════════
    // KPI — EFFECTIFS & FINANCES
    // (s'appuie sur les lots liés ; charger ->with('batches'))
    // ═══════════════════════════════════════════════

    public function getHeadCountAttribute(): int
    {
        return (int) $this->batches->sum('current_quantity');
    }

    public function getInitialHeadCountAttribute(): int
    {
        return (int) $this->batches->sum('initial_quantity');
    }

    /** Coût d'acquisition cumulé des lots liés. */
    public function getAcquisitionCostAttribute(): float
    {
        return (float) $this->batches->sum('total_acquisition_cost');
    }

    /**
     * Coût d'exploitation de la campagne — LA MÊME DÉCLARATION QUE LA MARGE.
     *
     * Ce total était recopié ici, et la copie avait divergé. Elle sommait les
     * ACHATS d'aliment (tous confondus) et les seuls actes du registre
     * sanitaire, en laissant de côté le traitement des INCIDENTS et les
     * dépenses directes validées du lot.
     *
     * Une épidémie traitée à 2 000 000 amputait donc la marge du lot d'autant et
     * le coût de la campagne de zéro — le défaut exact que `PeriodCharges` avait
     * corrigé pour le résultat de la ferme, laissé intact à l'échelon
     * intermédiaire. Deux écrans, deux coûts, pour les mêmes lots.
     *
     * `Batch::operating_cost` porte désormais la règle une seule fois. Le coût
     * d'ACQUISITION reste sur sa ligne propre (`acquisition_cost`) : il n'entre
     * pas dans l'exploitation, sans quoi `total_cost` le compterait deux fois.
     *
     * Sur le rendement : la marge lisait déjà `expenses()` et `healthIncidents()`
     * par le query builder, donc deux requêtes de plus par lot. Sur des
     * campagnes de ≤ 20 lots — la borne que ce fichier se donnait déjà pour
     * `utility_cost` — c'est du même ordre, et l'exactitude prime.
     */
    public function getOperatingCostAttribute(): float
    {
        return $this->batches->sum(fn (Batch $b) => $b->operating_cost);
    }

    public function getTotalCostAttribute(): float
    {
        return $this->acquisition_cost + $this->operating_cost;
    }

    /**
     * Chiffre d'affaires réalisé : lignes de vente validées/livrées
     * adossées aux lots de la campagne (animaux vifs, carcasses...).
     */
    public function getRealizedRevenueAttribute(): float
    {
        $batchIds = $this->batches->pluck('id');
        if ($batchIds->isEmpty()) {
            return 0.0;
        }

        return (float) SaleItem::whereIn('batch_id', $batchIds)
            ->whereHas('sale', fn ($q) => $q->whereIn('status', ['valide', 'livre']))
            ->sum('total');
    }

    /** Marge réalisée = CA réalisé − coûts engagés. */
    public function getRealizedMarginAttribute(): float
    {
        return $this->realized_revenue - $this->total_cost;
    }

    /** CA projeté = effectif courant × prix de vente cible. */
    public function getProjectedRevenueAttribute(): float
    {
        if (! $this->target_sale_price) {
            return 0.0;
        }
        return (float) $this->head_count * (float) $this->target_sale_price;
    }

    /** Marge projetée = CA projeté − coûts engagés. */
    public function getProjectedMarginAttribute(): float
    {
        return $this->projected_revenue - $this->total_cost;
    }

    /** Avancement vers l'objectif d'effectif (%). */
    public function getHeadProgressAttribute(): float
    {
        if (! $this->target_head_count) {
            return 0.0;
        }
        return min(100, round(($this->head_count / $this->target_head_count) * 100, 1));
    }
}
