<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasStandardUuid;

/**
 * Protocole de traitement / itinéraire technique (module Production Végétale).
 *
 * Pendant végétal de `Protocol` (prophylaxie d'élevage) : référentiel partagé
 * (non multi-ferme) décrivant, par culture et zone agro-écologique, les
 * interventions échelonnées en jours après semis (DAP). Rattachable à un cycle
 * pour générer un calendrier de rappels (CropProtocolAlertService).
 */
class CropProtocol extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid;

    /** Types d'intervention (clé => libellé FR + icône Font Awesome + couleur UI). */
    public const ITEM_TYPES = [
        'semis'         => ['label' => 'Semis/Plantation',  'icon' => 'fa-seedling',             'color' => 'lime'],
        'fertilisation' => ['label' => 'Fertilisation',     'icon' => 'fa-flask',                'color' => 'green'],
        'sarclage'      => ['label' => 'Sarclage',          'icon' => 'fa-trowel',               'color' => 'amber'],
        'traitement'    => ['label' => 'Traitement phyto',  'icon' => 'fa-spray-can-sparkles',   'color' => 'rose'],
        'irrigation'    => ['label' => 'Irrigation',        'icon' => 'fa-droplet',              'color' => 'cyan'],
        'observation'   => ['label' => 'Observation',       'icon' => 'fa-magnifying-glass',     'color' => 'indigo'],
        'recolte'       => ['label' => 'Récolte',           'icon' => 'fa-basket-shopping',      'color' => 'emerald'],
        'autre'         => ['label' => 'Autre',             'icon' => 'fa-circle',               'color' => 'slate'],
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'crop_name', 'agro_zone', 'name', 'description', 'source', 'is_active',
    ];

    protected $casts = [
        'is_synced'    => 'boolean',
        'last_sync_at' => 'datetime',
        'is_active'    => 'boolean',
    ];

    // ─── RELATIONS ───

    /** Étapes de l'itinéraire, triées par jour après semis (moteur d'alertes). */
    public function items(): HasMany
    {
        return $this->hasMany(CropProtocolItem::class)->orderBy('day_number', 'asc');
    }

    /** Cycles de culture pilotés par ce protocole. */
    public function cycles(): HasMany
    {
        return $this->hasMany(CropCycle::class, 'crop_protocol_id');
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── ACCESSEURS ───

    /** Durée totale de l'itinéraire (en jours) = plus grand day_number. */
    public function getDurationDaysAttribute(): int
    {
        return (int) ($this->relationLoaded('items')
            ? $this->items->max('day_number')
            : $this->items()->max('day_number')) ?? 0;
    }

    /** Libellé de la zone agro-écologique (ou « Toutes zones »). */
    public function getZoneLabelAttribute(): string
    {
        return CropSpecies::ZONES[$this->agro_zone] ?? 'Toutes zones';
    }
}
