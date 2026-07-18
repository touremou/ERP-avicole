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

    /** Seuil d'alerte « citerne basse » (%) — ravitaillement à prévoir. */
    public const LOW_LEVEL_PERCENT = 30;

    protected static function booted(): void
    {
        // Alerte automatique au FRANCHISSEMENT du seuil bas (≥30% → <30%) : une
        // seule notification par descente, quel que soit le chemin qui a baissé
        // le niveau (relevé de consommation, pointage journalier…). Un
        // ravitaillement qui repasse au-dessus « réarme » l'alerte suivante.
        static::updated(function (WaterSource $source) {
            if ($source->type !== 'citerne' || ! $source->capacity_liters) return;
            if (! $source->wasChanged('current_level_percent')) return;

            $old = (float) $source->getOriginal('current_level_percent');
            $new = (float) $source->current_level_percent;

            if ($old >= self::LOW_LEVEL_PERCENT && $new < self::LOW_LEVEL_PERCENT) {
                app(\App\Services\NotificationHub::class)->alertCiterneLow($source);
            }
        });
    }

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

        // Prend le RELEVÉ de consommation du jour (is_refill=false) : les lignes
        // de ravitaillement (appoints) ont déjà mis à jour le niveau directement.
        $todayReading = $this->readings()
            ->whereDate('reading_date', today())
            ->where('is_refill', false)
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
