<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\BelongsToFarm;

class Reception extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'dispatch_id', 'reception_number', 'received_by',
        'reception_date', 'reception_time',
        'status', 'photo_path', 'notes',
    ];

    protected $casts = [
        'reception_date' => 'date',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceptionItem::class);
    }

    public function discrepancyReport(): HasOne
    {
        return $this->hasOne(DiscrepancyReport::class);
    }

    // ─── ACCESSORS ───

    /**
     * Un écart existe si AU MOINS une ligne présente du manquant OU de
     * l'endommagé — définition alignée sur ReconciliationService (qui met la
     * réception en litige et génère un rapport dans les deux cas). Sans cet
     * alignement, une réception « endommagé seul » s'affichait « aucun écart »
     * tout en étant classée LITIGE.
     */
    public function getHasDiscrepancyAttribute(): bool
    {
        return $this->items()
            ->where(fn ($q) => $q->where('quantity_missing', '>', 0)->orWhere('quantity_damaged', '>', 0))
            ->exists();
    }

    public function getTotalReceivedAttribute(): float
    {
        return (float) $this->items()->sum('quantity_received');
    }

    public function getTotalMissingAttribute(): float
    {
        return (float) $this->items()->sum('quantity_missing');
    }
}
