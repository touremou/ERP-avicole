<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class WaterSource extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'name', 'type', 'capacity_liters',
        'current_level_liters', 'current_level_percent',
        'quality_status', 'is_active', 'is_default', 'notes', 'farm_id',
    ];

    protected $casts = [
        'capacity_liters'       => 'decimal:2',
        'current_level_liters'  => 'decimal:2',
        'current_level_percent' => 'decimal:2',
        'is_active'             => 'boolean',
        'is_default'            => 'boolean',
    ];

    public function readings(): HasMany
    {
        return $this->hasMany(WaterReading::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeCritical($query)
    {
        return $query->where('type', 'citerne')
            ->where('is_active', true)
            ->whereNotNull('capacity_liters')
            ->whereRaw('current_level_percent < 30');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'seeg'    => 'SEEG (Réseau)',
            'forage'  => 'Forage',
            'citerne' => 'Citerne',
            'camion'  => 'Camion-citerne',
            default   => $this->type,
        };
    }

    public function getIsLowAttribute(): bool
    {
        if ($this->type !== 'citerne' || ! $this->capacity_liters) return false;
        return ($this->current_level_percent ?? 0) < 30;
    }

    /**
     * Met à jour le niveau de la citerne après un relevé.
     */
    public function refreshLevel(): void
    {
        if ($this->type !== 'citerne' || ! $this->capacity_liters) return;

        // Prend le RELEVÉ du jour (consommation la plus élevée) plutôt qu'un
        // simple appoint (ravitaillement, consommation 0) qui a déjà mis à jour
        // le niveau directement — évite de re-compter un ravitaillement.
        $todayReading = $this->readings()
            ->whereDate('reading_date', today())
            ->orderByDesc('volume_consumed_liters')
            ->first();
        if (! $todayReading) return;

        $newLevel = max(0, (float) $this->current_level_liters
            - (float) $todayReading->volume_consumed_liters
            + (float) $todayReading->volume_added_liters);

        // Anti-débordement : une citerne ne peut pas dépasser sa capacité.
        $newLevel = min((float) $this->capacity_liters, $newLevel);

        $percent = ($this->capacity_liters > 0) ? ($newLevel / $this->capacity_liters) * 100 : 0;

        $this->update([
            'current_level_liters'  => $newLevel,
            'current_level_percent' => min(100, $percent),
        ]);
    }
}
