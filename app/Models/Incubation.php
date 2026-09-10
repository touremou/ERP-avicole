<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasStandardUuid; // Trait utilisé sur Batch
use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incubation extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    protected $fillable = [
        
        'uuid',
        'mirage_uuid',
        'hatch_uuid',
        'farm_id',
        'batch_id',
        // Provenance des œufs : `internal` (prélevés au magasin, donc déstockés)
        // ou `external` (achetés, jamais entrés en stock). `egg_grade` dit quel
        // calibre a été prélevé — sans lui, rien à restituer à l'abandon.
        'source_type',
        'egg_grade',
        'incubator_id',
        'code_incubation',
        'start_date',
        'incubation_duration',
        'hatch_date_expected',
        'eggs_count',
        'egg_unit_cost',
        'overhead_cost',
        'fertile_eggs',
        'hatched_chicks',
        'status',
        // `RecordHatching` écrit `finished_at => now()` à la clôture ; absent de
        // cette liste, l'assignation de masse le jetait SANS UN MOT. La colonne
        // existe depuis la migration d'origine et restait donc toujours nulle :
        // une écriture qui n'écrivait pas.
        'finished_at',
        'chicks_dispatched', 'chicks_remaining', // incubation, mirage_fait, clos
    ];

    protected $casts = [
        'start_date'          => 'date',
        'hatch_date_expected' => 'date',
        'finished_at'         => 'datetime',
        'incubation_duration' => 'integer',
        'eggs_count'          => 'integer',
        'egg_unit_cost'       => 'decimal:2',
        'overhead_cost'       => 'decimal:2',
        'fertile_eggs'        => 'integer',
        'hatched_chicks'      => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    /**
     * Cycles descendus au terrain (M5) : ceux encore OUVERTS — le mirage et
     * l'éclosion se font en salle d'incubation. Un cycle clos ne sert plus.
     */
    public function scopeOpenForSync($query)
    {
        return $query->whereIn('status', ['incubation', 'mirage_fait']);
    }

    /** Coût total des œufs mis à couver (eggs_count × coût unitaire). */
    public function eggsTotalCost(): float
    {
        return (float) $this->eggs_count * (float) $this->egg_unit_cost;
    }

    /**
     * Coût total du cycle (absorption complète, « version usine ») :
     * coût des œufs + frais d'incubation (énergie, main-d'œuvre, amortissement).
     */
    public function totalProcessCost(): float
    {
        return $this->eggsTotalCost() + (float) $this->overhead_cost;
    }

    /**
     * Coût de revient d'UN poussin éclos (process costing) : le coût total du
     * cycle (œufs + frais d'incubation) est réparti sur les poussins réellement
     * éclos — œufs clairs / non éclos absorbés par les survivants (coût réel d'un
     * poussin viable). Retourne 0 tant qu'aucun poussin n'est éclos.
     */
    public function chickUnitCost(): float
    {
        $hatched = (int) $this->hatched_chicks;
        if ($hatched <= 0) {
            return 0.0;
        }

        return round($this->totalProcessCost() / $hatched, 2);
    }

    // Accessors virtuels pour les calculs de performance
    protected $appends = ['fertility_rate', 'hatchability_rate', 'progress_days']; 

    // -----------------------
    // RELATIONS
    // -----------------------

    public function batch(): BelongsTo 
    {
        return $this->belongsTo(Batch::class);
    }

    public function incubator(): BelongsTo 
    {
        return $this->belongsTo(Incubator::class)->withTrashed(); // Garde le lien même si la machine est réformée
    }

    public function chickDispatches(): HasMany
    {
    return $this->hasMany(\App\Models\ChickDispatch::class);
    }

    public function getChicksRemainingAttribute(): int
    {
        return max(0, ($this->hatched_chicks ?? 0) - ($this->chicks_dispatched ?? 0));
    }
    // -----------------------
    // ACCESSEURS (KPI PERFORMANCE)
    // -----------------------
    /*
    public function getFertilityRateAttribute(): float 
    {
        if (!$this->eggs_count || is_null($this->fertile_eggs)) return 0.0;
        return round(($this->fertile_eggs / $this->eggs_count) * 100, 1);
    }

    public function getHatchabilityRateAttribute(): float 
    {
        if (!$this->fertile_eggs || is_null($this->hatched_chicks)) return 0.0;
        return round(($this->hatched_chicks / $this->fertile_eggs) * 100, 1);
    }
    */
    // Dans app/Models/Incubation.php

    public function getFertilityRateAttribute(): float
    {
        if ($this->eggs_count <= 0) return 0.0;
        return round(($this->fertile_eggs / $this->eggs_count) * 100, 1);
    }

    public function getHatchabilityRateAttribute(): float
    {
        if ($this->fertile_eggs <= 0) return 0.0;
        return round(($this->hatched_chicks / $this->fertile_eggs) * 100, 1);
    }

    public function getProgressDaysAttribute(): int
    {
        if (!$this->start_date) return 0;
        return (int) $this->start_date->diffInDays(now());
    }

    public function getIsMirageLateAttribute(): bool
    {
        return $this->status === 'incubation' && $this->progress_days >= 10;
    }

    // -----------------------
    // SCOPES
    // -----------------------

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'clos');
    }

    public function scopeLate($query)
    {
        return $query->where('status', '!=', 'clos')
                     ->where('hatch_date_expected', '<', now());
    }

    /**
     * Cycles CONCLUS depuis `$depuis` — mirage fait, ou éclosion close.
     *
     * Un cycle se date de son ÉVÉNEMENT, pas de sa dernière retouche. Les KPI du
     * couvoir se bornaient sur `updated_at`, qui n'est la date de rien :
     * `ChickDispatchController::refreshCounters()` réécrit la ligne à CHAQUE
     * départ de poussins, et les départs s'étalent sur des semaines. Un cycle
     * éclos il y a quarante jours, dont on sortait les derniers poussins le
     * matin même, rentrait donc dans la fenêtre des trente jours avec la
     * TOTALITÉ de son éclosion — le mois écoulé se voyait créditer des poussins
     * nés le mois d'avant.
     *
     * Pour un cycle CLOS, cette date est `finished_at`, écrite par
     * `RecordHatching`. C'est déjà celle que l'ERP lit partout ailleurs pour
     * dater la production : la `birth_date` des poussins dispatchés, le
     * « Produit le » de la traçabilité, et le mois du tableau de bord de la
     * provenderie, qui pose la même question sur ses lots de fabrication.
     *
     * Pour un cycle MIRÉ, il n'y a rien à dater d'autre : il n'est pas fini, il
     * n'a pas de `finished_at`, et sa dernière écriture EST son mirage. Le
     * basculer lui aussi sur `finished_at` aurait vidé la fertilité moyenne
     * sans un mot.
     *
     * Les lignes closes d'avant l'existence de la colonne retombent sur
     * `updated_at`, comme le fait déjà la traçabilité.
     */
    public function scopeConcludedSince($query, $depuis)
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($clos) => $clos
                ->where('status', 'clos')
                ->whereRaw('COALESCE(finished_at, updated_at) >= ?', [$depuis]))
            ->orWhere(fn ($mire) => $mire
                ->where('status', 'mirage_fait')
                ->where('updated_at', '>=', $depuis)));
    }
}