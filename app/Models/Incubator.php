<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;

class Incubator extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'name', 
        'capacity', 
        'status' // Disponible, Occupé, Maintenance, Panne
    ];

    protected $casts = [
        'capacity'   => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    public function incubations(): HasMany
    {
        return $this->hasMany(Incubation::class);
    }

    public function activeIncubation(): HasOne
    {
        return $this->hasOne(Incubation::class)->where('status', '!=', 'clos');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(IncubatorMaintenance::class)->latest();
    }

    // -----------------------
    // ACCESSEURS (LOGIQUE MÉTIER)
    // -----------------------

    public function getGlobalSuccessRateAttribute(): float
    {
        $closedCycles = $this->incubations()->where('status', 'clos')->get();
        if ($closedCycles->isEmpty()) return 0.0;

        return round($closedCycles->avg('hatchability_rate'), 1);
    }

    /**
     * TOUS les cycles en cours de cet incubateur — pas seulement le premier.
     *
     * `activeIncubation()` est un hasOne : avec deux cycles simultanés, il en rend
     * un arbitrairement et ignore l'autre. C'est ce qui faisait sous-estimer le
     * remplissage de la machine.
     *
     * L'incubation MULTI-ÉTAGES — plusieurs mises à couver à des dates différentes
     * dans la même machine — est une pratique courante, et l'application doit la
     * permettre. Ce qu'elle doit empêcher, c'est le DÉPASSEMENT de capacité.
     */
    public function activeIncubations(): HasMany
    {
        return $this->hasMany(Incubation::class)->where('status', '!=', 'clos');
    }

    /**
     * ŒUFS ACTUELLEMENT DANS LA MACHINE — déclaration unique.
     *
     * Somme de tous les cycles non clos. Sert à la fois au taux de remplissage
     * affiché et au contrôle de capacité à la mise à couver : deux calculs
     * divergents diraient deux vérités sur la même machine.
     */
    public function eggsInIncubation(): int
    {
        return (int) $this->activeIncubations()->sum('eggs_count');
    }

    /**
     * PLACE RESTANTE. Jamais négative : une machine déjà surchargée en rend zéro,
     * plutôt qu'un nombre qui autoriserait à charger encore.
     */
    public function remainingCapacity(): int
    {
        return max(0, (int) $this->capacity - $this->eggsInIncubation());
    }

    /**
     * TAUX DE REMPLISSAGE.
     *
     * Il ne comptait QUE le premier cycle trouvé (hasOne). Une machine portant deux
     * mises à couver affichait donc la moitié de sa charge réelle — et le écran de
     * planification s'en servait pour décider s'il restait de la place.
     */
    public function getOccupancyRateAttribute(): float
    {
        if ($this->capacity <= 0) {
            return 0.0;
        }

        return round(($this->eggsInIncubation() / $this->capacity) * 100, 1);
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Disponible'  => 'emerald',
            'Occupé'      => 'blue',
            'Maintenance' => 'orange',
            'Panne'       => 'rose',
            default       => 'slate',
        };
    }

    // -----------------------
    // SCOPES DE FILTRAGE
    // -----------------------

    public function scopeAvailable($query)
    {
        return $query->where('status', 'Disponible');
    }

    public function scopeInProduction($query)
    {
        return $query->where('status', 'Occupé');
    }

    public function scopeNeedsMaintenance($query)
    {
        return $query->whereDoesntHave('maintenances', function($q) {
            $q->where('maintenance_date', '>', now()->subDays(90));
        });
    }
}