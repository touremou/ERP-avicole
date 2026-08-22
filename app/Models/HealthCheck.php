<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;

class HealthCheck extends Model
{
    /*
     * LA COLONNE `deleted_at` EXISTAIT ; LE TRAIT, NON.
     *
     * `health_checks` porte `deleted_at` depuis sa création — la suppression
     * douce était donc prévue — mais le modèle ne l'a jamais utilisée. Or
     * `BatchObserver::deleting()` cascade `$batch->healthChecks()->delete()` :
     * sans le trait, cet appel DÉTRUISAIT définitivement les vaccinations, les
     * traitements et leurs coûts, alors même que le lot, lui, partait à la
     * corbeille et se présentait comme récupérable.
     *
     * Un registre sanitaire est une pièce réglementaire : il ne doit pas
     * disparaître comme effet de bord de la mise en corbeille d'un lot.
     */
    use HasFactory, BelongsToFarm, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'batch_id',
        'intervention_date',
        'type',                // Vaccin, Traitement, Vitamine, Désinfection
        'product_name',
        'batch_number',        // Numéro de lot fabricant
        'expiry_date',         // Date de péremption du produit
        'mode_administration', // Eau, Injection, Pulvérisation, etc.
        'withdrawal_days',     // Délai d'attente avant consommation (notice produit)
        'cost',
        'veterinary_name',
        'observations',
    ];

    protected $casts = [
        'intervention_date' => 'date',
        'expiry_date' => 'date',
        'withdrawal_days' => 'integer',
        'cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // -----------------------
    // DÉLAI D'ATTENTE (sécurité alimentaire)
    // -----------------------

    /**
     * Date de FIN du délai d'attente : à partir de ce jour, la viande/les œufs
     * du lot redeviennent consommables. Null si le produit n'impose aucun délai.
     */
    public function getWithdrawalUntilAttribute(): ?Carbon
    {
        if (! $this->withdrawal_days || ! $this->intervention_date) {
            return null;
        }

        return $this->intervention_date->copy()->addDays((int) $this->withdrawal_days);
    }

    /**
     * Le délai d'attente COUVRE-T-IL une date donnée ?
     *
     * Une denrée est impropre si elle a été PRODUITE pendant la fenêtre
     * [intervention ; échéance[ — ce qui compte est la date de production, pas
     * la date où on la manipule. Pour la viande les deux se confondent (on
     * abat aujourd'hui), pour les œufs non : une ponte du 3 triée le 10 reste
     * une ponte du 3.
     *
     * C'est cette distinction qui manquait, et elle ne se voyait pas tant que
     * la règle n'avait qu'un seul lecteur — l'abattage.
     */
    public function coversDate(Carbon|string $date): bool
    {
        $until = $this->withdrawal_until;
        if ($until === null || ! $this->intervention_date) {
            return false;
        }

        $day = ($date instanceof Carbon ? $date->copy() : Carbon::parse($date))->startOfDay();

        return $day->gte($this->intervention_date->copy()->startOfDay()) && $day->lt($until);
    }

    /** Le délai d'attente court-il encore aujourd'hui ? */
    public function isUnderWithdrawal(): bool
    {
        return $this->coversDate(now());
    }

    /** Jours restants avant la fin du délai d'attente (0 si purgé/sans délai). */
    public function getWithdrawalDaysLeftAttribute(): int
    {
        $until = $this->withdrawal_until;
        if ($until === null) {
            return 0;
        }

        return max(0, now()->startOfDay()->diffInDays($until, false));
    }

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