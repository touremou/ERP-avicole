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
     * Applique au niveau de la citerne la VARIATION d'un relevé.
     *
     * Cette méthode s'appelait `refreshLevel()` et relisait le relevé du jour
     * pour en soustraire la consommation ENTIÈRE. Or `RecordWaterReading` fait
     * un `updateOrCreate` : corriger le relevé du jour (« 500 L, non, 600 »)
     * rappelait la méthode, qui retirait 600 de plus d'un niveau qui portait
     * déjà les 500 premiers. La citerne perdait 1 100 L pour une journée qui en
     * avait consommé 600 — puis l'alerte « citerne basse » se déclenchait sur
     * un niveau qui n'existait pas.
     *
     * On applique donc la VARIATION, sur le modèle de `SyncWaterConsumption`,
     * qui dit en toutes lettres appliquer le delta « si bien qu'une
     * rectification ou une suppression de pointage réajuste le niveau sans
     * jamais double-compter ». La règle était déjà écrite ; elle n'était pas
     * tenue des deux côtés.
     *
     * Les lignes de ravitaillement ne passent pas par ici : elles mettent le
     * niveau à jour directement, à leur création (elles sont des événements, et
     * plusieurs par jour coexistent).
     */
    public function applyReadingDelta(float $consumedDelta, float $addedDelta): void
    {
        if ($this->type !== 'citerne' || ! $this->capacity_liters) return;

        if (abs($consumedDelta) < 0.0001 && abs($addedDelta) < 0.0001) return;

        $newLevel = max(0, (float) $this->current_level_liters - $consumedDelta + $addedDelta);

        // Anti-débordement : une citerne ne peut pas dépasser sa capacité.
        $newLevel = min((float) $this->capacity_liters, $newLevel);

        $percent = ($this->capacity_liters > 0) ? ($newLevel / $this->capacity_liters) * 100 : 0;

        $this->update([
            'current_level_liters'  => $newLevel,
            'current_level_percent' => min(100, $percent),
        ]);
    }
}
