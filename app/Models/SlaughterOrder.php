<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AuditsChanges;
use App\Traits\BelongsToFarm;

class SlaughterOrder extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm, AuditsChanges;

    protected $fillable = [
        'farm_id',
        'order_number', 'batch_id', 'reception_id', 'planned_date', 'actual_date',
        'planned_quantity', 'actual_quantity', 'total_live_weight_kg',
        'status', 'requested_by', 'executed_by', 'client_id', 'notes',
    ];

    // Blocage/libération HACCP : champs volontairement HORS fillable —
    // posés uniquement par les Actions Block/ReleaseSlaughterOrder
    // (forceFill), tracés par l'audit trail (AuditsChanges).

    protected $casts = [
        'planned_date'         => 'date',
        'actual_date'          => 'date',
        'total_live_weight_kg' => 'decimal:2',
        'blocked_at'           => 'datetime',
        'released_at'          => 'datetime',
    ];

    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function executor(): BelongsTo { return $this->belongsTo(User::class, 'executed_by'); }
    public function result(): HasOne { return $this->hasOne(SlaughterResult::class); }
    public function cuttingSessions(): HasMany { return $this->hasMany(CuttingSession::class); }
    public function reception(): BelongsTo { return $this->belongsTo(SlaughterReception::class, 'reception_id'); }
    public function ccpRecords(): HasMany { return $this->hasMany(CcpRecord::class); }
    public function byproducts(): HasMany { return $this->hasMany(SlaughterByproduct::class); }
    public function blockedBy(): BelongsTo { return $this->belongsTo(User::class, 'blocked_by_id'); }
    public function releasedBy(): BelongsTo { return $this->belongsTo(User::class, 'released_by_id'); }

    public function scopePending($query) { return $query->whereIn('status', ['planifie', 'en_cours']); }

    /** RG-03 : un lot bloqué sort du circuit (découpe, stock, vente). */
    public function isBlocked(): bool { return $this->status === 'bloque'; }

    public function getAvgLiveWeightAttribute(): ?float
    {
        if (! $this->actual_quantity || ! $this->total_live_weight_kg) return null;
        return round($this->total_live_weight_kg / $this->actual_quantity, 3);
    }

    public static function generateNumber(): string
    {
        return \App\Services\DocumentNumberingService::generate('slaughter_order');
    }
}
