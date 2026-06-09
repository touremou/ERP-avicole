<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;

class HealthCheck extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'batch_id',
        'intervention_date',
        'type',                // Vaccin, Traitement, Vitamine, Désinfection
        'product_name',
        'batch_number',        // Numéro de lot fabricant
        'expiry_date',         // Date de péremption du produit
        'mode_administration', // Eau, Injection, Pulvérisation, etc.
        'cost',
        'veterinary_name',
        'observations',
    ];

    protected $casts = [
        'intervention_date' => 'date',
        'expiry_date' => 'date',
        'cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    // -----------------------
    // ACCESSEURS (LOGIQUE DE VIGILANCE)
    // -----------------------

    /**
     * Vérifie si le produit utilisé était périmé au moment de l'intervention.
     * Crucial pour les audits de qualité et les litiges sanitaires.
     */
    public function getWasExpiredAtInterventionAttribute(): bool
    {
        if (!$this->expiry_date || !$this->intervention_date) {
            return false;
        }
        return $this->expiry_date->isBefore($this->intervention_date);
    }

    /**
     * Calcule le coût par sujet pour cette intervention.
     * Permet d'analyser l'impact financier de la santé sur le prix de revient.
     */
    public function getCostPerBirdAttribute(): float
    {
        if (!$this->cost || !$this->batch || $this->batch->current_quantity <= 0) {
            return 0.0;
        }
        return round((float) $this->cost / $this->batch->current_quantity, 2);
    }

    /**
     * Badge de couleur pour le type d'intervention (AviSmart UI)
     */
    public function getTypeColorAttribute(): string
    {
        return match($this->type) {
            'Vaccin'      => 'indigo',
            'Traitement'  => 'rose',
            'Vitamine'    => 'emerald',
            'Désinfection' => 'slate',
            default       => 'gray',
        };
    }

    // -----------------------
    // SCOPES (FILTRAGE)
    // -----------------------

    public function scopeVaccines($query)
    {
        return $query->where('type', 'Vaccin');
    }

    public function scopeRecent($query)
    {
        return $query->where('intervention_date', '>=', now()->subDays(30));
    }

    public function scopeByBatch($query, $batchId)
    {
        return $query->where('batch_id', $batchId);
    }
}