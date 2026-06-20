<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasStandardUuid;

/**
 * Espèce / culture du catalogue agronomique (module Production Végétale).
 *
 * Référentiel partagé (non multi-ferme) servant de base de connaissances :
 * durée de cycle, rendement de référence, nom local guinéen. Pré-remplit un
 * cycle de culture et sert de benchmark au rendement réel.
 */
class CropSpecies extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid;

    protected $table = 'crop_species';

    /** Types de cultures (libellés FR + icône Font Awesome pour l'affichage). */
    public const TYPES = [
        'cereale'     => ['label' => 'Céréales',     'icon' => 'fa-wheat-awn',     'color' => 'amber'],
        'legume'      => ['label' => 'Légumes',      'icon' => 'fa-leaf',          'color' => 'green'],
        'tubercule'   => ['label' => 'Tubercules',   'icon' => 'fa-carrot',        'color' => 'orange'],
        'fruitier'    => ['label' => 'Fruitiers',    'icon' => 'fa-apple-whole',   'color' => 'rose'],
        'legumineuse' => ['label' => 'Légumineuses', 'icon' => 'fa-seedling',      'color' => 'lime'],
        'oleagineux'  => ['label' => 'Oléagineux',   'icon' => 'fa-sun',           'color' => 'yellow'],
        'maraicher'   => ['label' => 'Maraîchers',   'icon' => 'fa-pepper-hot',    'color' => 'red'],
        'autre'       => ['label' => 'Autre',        'icon' => 'fa-sprout',        'color' => 'slate'],
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'type', 'name', 'local_name', 'family',
        'cycle_days_min', 'cycle_days_max', 'avg_yield_tha',
        'description', 'is_active',
    ];

    protected $casts = [
        'is_synced'      => 'boolean',
        'last_sync_at'   => 'datetime',
        'is_active'      => 'boolean',
        'cycle_days_min' => 'integer',
        'cycle_days_max' => 'integer',
        'avg_yield_tha'  => 'decimal:2',
    ];

    // ─── RELATIONS ───

    public function varieties(): HasMany
    {
        return $this->hasMany(CropVariety::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst((string) $this->type);
    }

    public function getTypeIconAttribute(): string
    {
        return self::TYPES[$this->type]['icon'] ?? 'fa-sprout';
    }

    public function getTypeColorAttribute(): string
    {
        return self::TYPES[$this->type]['color'] ?? 'slate';
    }

    /** Durée de cycle lisible (« 90–120 j » ou « 90 j »). */
    public function getCycleLabelAttribute(): ?string
    {
        if (! $this->cycle_days_min && ! $this->cycle_days_max) {
            return null;
        }
        if ($this->cycle_days_min && $this->cycle_days_max && $this->cycle_days_min !== $this->cycle_days_max) {
            return "{$this->cycle_days_min}–{$this->cycle_days_max} j";
        }

        return ($this->cycle_days_min ?: $this->cycle_days_max) . ' j';
    }
}
