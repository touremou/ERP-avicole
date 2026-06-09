<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Protocol extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Rigueur ERP : Centralisation des types de production supportés
     */
    protected $fillable = [
        'name', 
        'type',    // chair, ponte, poussiniere, reproducteur
        'strain',  // Cobb500, Ross308, Lohmann, etc.
        'description'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Un protocole possède plusieurs étapes chronologiques.
     * Rigueur : Tri ascendant systématique pour le moteur d'alertes.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ProtocolStep::class)->orderBy('day_number', 'asc');
    }

    /**
     * Lots (bandes) utilisant actuellement ce protocole.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    // -----------------------
    // ACCESSEURS (KPI & UI)
    // -----------------------

    /**
     * Retourne la durée totale du protocole (en jours).
     */
    public function getDurationDaysAttribute(): int
    {
        return (int) $this->steps()->max('day_number') ?? 0;
    }

    /**
     * Label formaté combinant Nom et Souche.
     */
    public function getFullNameAttribute(): string
    {
        return $this->strain 
            ? "{$this->name} ({$this->strain})" 
            : $this->name;
    }

    /**
     * Badge de couleur pour le type de production (AviSmart UI).
     */
    public function getTypeColorAttribute(): string
    {
        return match($this->type) {
            'chair'        => 'orange',
            'ponte'        => 'emerald',
            'poussiniere'  => 'blue',
            'reproducteur' => 'purple',
            default        => 'gray',
        };
    }

    // -----------------------
    // SCOPES (FILTRAGE)
    // -----------------------

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        // Utile pour n'afficher que les protocoles liés à des lots en cours
        return $query->whereHas('batches', function($q) {
            $q->where('status', 'Actif');
        });
    }
}