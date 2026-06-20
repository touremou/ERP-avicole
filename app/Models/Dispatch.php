<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\BelongsToFarm;

class Dispatch extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id', 'dispatch_number', 'sale_id', 'dispatched_by', 'intended_receiver_id',
        'vehicle_plate', 'driver_name', 'driver_phone',
        'dispatch_date', 'dispatch_time', 'destination',
        'status', 'notes', 'photo_path',
    ];

    protected $casts = [
        'dispatch_date' => 'date',
    ];

    // ─── RELATIONS ───

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * Récepteur désigné à la création (compte utilisateur). Notifié à
     * l'expédition, il est habilité à valider la réception (à défaut, un
     * responsable logistique.M le fait en secours).
     */
    public function intendedReceiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'intended_receiver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DispatchItem::class);
    }

    public function reception(): HasOne
    {
        return $this->hasOne(Reception::class);
    }

    public function discrepancyReport(): HasOne
    {
        return $this->hasOne(DiscrepancyReport::class);
    }

    // ─── SCOPES ───

    public function scopePending($query)
    {
        return $query->whereIn('status', ['prepare', 'expedie', 'en_route']);
    }

    // ─── ACCESSORS ───

    public function getIsReceivedAttribute(): bool
    {
        return in_array($this->status, ['receptionne', 'clos']);
    }

    public function getTotalDispatchedAttribute(): float
    {
        return (float) $this->items()->sum('quantity_dispatched');
    }
}
