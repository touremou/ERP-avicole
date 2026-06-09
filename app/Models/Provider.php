<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class Provider extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'provider_id', 
        'name', 
        'type',          // Poussins, Aliment, Santé, Matériel, Autre
        'domain', 
        'phone', 
        'email', 
        'address', 
        'rccm', 
        'nif', 
        'payment_terms', 
        'reliability',   // Bon, Moyen, Mauvais
        'status'         // Actif, Blacklisté, Inactif
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Relation avec les lots (Bandes).
     * Permet d'analyser la qualité des poussins par couvoir.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * Relation avec les achats d'aliments ou médicaments.
     */
    public function feedPurchases(): HasMany
    {
        return $this->hasMany(FeedPurchase::class, 'supplier', 'name');
    }

    // -----------------------
    // LOGIQUE MÉTIER & KPI
    // -----------------------

    /**
     * Génération automatique du Matricule Fournisseur.
     * Rigueur : Utilise withTrashed() pour éviter de réutiliser un ID supprimé.
     */
    protected static function booted() 
    {
        static::creating(function ($provider) {
            if (empty($provider->provider_id)) {
                $count = static::withTrashed()->whereYear('created_at', date('Y'))->count() + 1;
                $provider->provider_id = 'PROV-' . date('Y') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * KPI : Taux de mortalité moyen des lots fournis par ce partenaire.
     * Un indicateur critique pour le choix de vos couvoirs.
     */
    public function getAverageMortalityRateAttribute(): float
    {
        // On ne calcule que sur les lots terminés
        $closedBatches = $this->batches()->where('status', 'Terminé')->get();
        if ($closedBatches->isEmpty()) return 0.0;

        return round($closedBatches->avg('mortality_rate') ?? 0, 2);
    }

    /**
     * Couleur UI selon la fiabilité.
     */
    public function getReliabilityColorAttribute(): string
    {
        return match($this->reliability) {
            'Bon'     => 'emerald',
            'Moyen'   => 'orange',
            'Mauvais' => 'rose',
            default   => 'slate',
        };
    }

    // -----------------------
    // SCOPES (FILTRAGE)
    // -----------------------

    public function scopeChicks($query)
    {
        return $query->where('type', 'Poussins');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Actif');
    }
}