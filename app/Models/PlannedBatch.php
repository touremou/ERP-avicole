<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;

class PlannedBatch extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id', 'building_id', 'batch_type', 'species_id', 'production_type_id',
        'model_name', 'planned_quantity',
        'planned_arrival_date', 'planned_end_date',
        'sanitary_void_start', 'sanitary_void_end',
        'chick_order_deadline', 'provider_id',
        'status', 'actual_batch_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'planned_arrival_date' => 'date',
        'planned_end_date'     => 'date',
        'sanitary_void_start'  => 'date',
        'sanitary_void_end'    => 'date',
        'chick_order_deadline' => 'date',
    ];

    public function species(): BelongsTo { return $this->belongsTo(Species::class); }
    public function productionType(): BelongsTo { return $this->belongsTo(ProductionType::class); }

    /**
     * Durées standards par type (en jours).
     */
    public static function getCycleDays(string $type): int
    {
        return setting("elevage.cycle_{$type}", match($type) {
            'chair' => 42, 'ponte' => 540, 'reproducteur' => 450, 'poussiniere' => 90, default => 42
        });
    }

    public const SANITARY_VOID_DAYS = 21;
    public const CHICK_ORDER_LEAD_DAYS = 56; // 8 semaines

    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
    public function provider(): BelongsTo { return $this->belongsTo(Provider::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function actualBatch(): BelongsTo { return $this->belongsTo(Batch::class, 'actual_batch_id'); }

    public function scopeUpcoming($query) { return $query->where('status', 'planifie')->where('planned_arrival_date', '>', now())->orderBy('planned_arrival_date'); }
    public function scopeOverdue($query) { return $query->where('status', 'planifie')->where('chick_order_deadline', '<', now()); }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'planifie' && $this->chick_order_deadline && $this->chick_order_deadline->isPast();
    }

    public function getDaysUntilArrivalAttribute(): int
    {
        return max(0, (int) now()->diffInDays($this->planned_arrival_date, false));
    }

    /**
     * Calcule automatiquement les dates à partir de la date d'arrivée.
     */
    public static function calculateDates(string $type, Carbon $arrivalDate, ?int $cycleOverride = null): array
    {
        // Priorité au cycle du type de production (multiespèces) ; repli sur
        // les durées paramétrées/legacy par slug.
        $cycleDays = $cycleOverride ?? self::getCycleDays($type);

        $endDate = $arrivalDate->copy()->addDays($cycleDays);
        $voidStart = $endDate->copy()->addDay();
        $voidEnd = $voidStart->copy()->addDays(self::SANITARY_VOID_DAYS);
        $orderDeadline = $arrivalDate->copy()->subDays(self::CHICK_ORDER_LEAD_DAYS);

        return [
            'planned_end_date'    => $endDate,
            'sanitary_void_start' => $voidStart,
            'sanitary_void_end'   => $voidEnd,
            'chick_order_deadline' => $orderDeadline,
        ];
    }
}
