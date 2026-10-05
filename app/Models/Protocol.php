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
        'species_id', // Espèce visée (null = protocole générique, toutes espèces)
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
    /**
     * L'ESPÈCE SE DÉDUIT DE LA SOUCHE, à chaque enregistrement.
     *
     * Les formulaires choisissent déjà une souche ; une souche connaît son
     * espèce (production_norms.species_id). Déduite ici, l'espèce est posée par
     * toutes les portes à la fois — création, modification, création rapide,
     * import JSON, semeur — sans champ de plus à remplir. Repli sur le nom
     * (« Prophylaxie Dinde ») ; rien de reconnu : protocole générique.
     */
    protected static function booted(): void
    {
        static::saving(function (Protocol $protocol) {
            if ($protocol->species_id && ! $protocol->isDirty(['strain', 'name'])) {
                return;
            }

            $species = $protocol->strain
                ? ProductionNorm::where('model_name', $protocol->strain)->whereNotNull('species_id')->value('species_id')
                : null;

            if (! $species && $slug = ProductionNorm::guessSpeciesSlug(trim($protocol->name . ' ' . $protocol->strain))) {
                $species = Species::where('slug', $slug)->value('id');
            }

            $protocol->species_id = $species ?: null;
        });
    }

    /** Espèce visée — null pour un protocole générique. */
    public function species(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /**
     * CE PROTOCOLE CONVIENT-IL À CETTE BANDE ? — une seule déclaration, lue par
     * les écrans (filtrage) ET par le serveur (refus) : un sélecteur filtré ne
     * protège pas d'une requête forgée ni d'un formulaire resté ouvert.
     *
     * Même type de production, et même espèce sauf protocole générique. Le TYPE
     * seul ne suffisait pas : « Prophylaxie Dinde » (type chair) s'offrait pour
     * un poulet de chair.
     */
    public function convientA(?string $batchType, ?int $speciesId): bool
    {
        if ($batchType && $this->type && $this->type !== $batchType) {
            return false;
        }

        return ! $this->species_id || ! $speciesId || (int) $this->species_id === (int) $speciesId;
    }

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