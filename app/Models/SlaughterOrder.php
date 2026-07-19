<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AuditsChanges;
use App\Traits\BelongsToFarm;

class SlaughterOrder extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm, AuditsChanges;

    /** Modèles de facturation de la prestation d'abattage à façon (E8). */
    public const BILLING_MODELS = [
        'par_sujet'       => 'Par sujet abattu',
        'par_kg_vif'      => 'Au kg vif (pesée réception)',
        'par_kg_carcasse' => 'Au kg carcasse (pesée sortie)',
    ];

    /** Clé de réglage du tarif par défaut de chaque modèle. */
    public const BILLING_RATE_SETTINGS = [
        'par_sujet'       => 'abattoir.facon_rate_per_bird',
        'par_kg_vif'      => 'abattoir.facon_rate_per_kg_live',
        'par_kg_carcasse' => 'abattoir.facon_rate_per_kg_carcass',
    ];

    protected $fillable = [
        'farm_id',
        'order_number', 'batch_id', 'reception_id', 'planned_date', 'actual_date',
        'planned_quantity', 'actual_quantity', 'total_live_weight_kg',
        'status', 'requested_by', 'executed_by', 'client_id', 'notes',
        'service_type', 'billing_model', 'billing_rate',
    ];

    // Blocage/libération HACCP : champs volontairement HORS fillable —
    // posés uniquement par les Actions Block/ReleaseSlaughterOrder
    // (forceFill), tracés par l'audit trail (AuditsChanges).

    protected $casts = [
        'planned_date'         => 'date',
        'actual_date'          => 'date',
        'total_live_weight_kg' => 'decimal:2',
        'blocked_at'           => 'datetime',
        'released_at'          => 'datetime',
        'closed_at'            => 'datetime',
        'closure_checklist'    => 'array',
        'billing_rate'         => 'decimal:2',
        'service_fee'          => 'decimal:2',
    ];

    /** Confirmations OBLIGATOIRES de la checklist de clôture (déchets + HACCP). */
    public const CLOSURE_CONFIRMATIONS = ['waste_evacuated', 'zone_cleaned', 'marche_avant'];

    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function executor(): BelongsTo { return $this->belongsTo(User::class, 'executed_by'); }
    public function result(): HasOne { return $this->hasOne(SlaughterResult::class); }
    public function cuttingSessions(): HasMany { return $this->hasMany(CuttingSession::class); }
    /** Transformations (fumage, marinade...) rattachées à cet ordre (cascade). */
    public function transformations(): HasMany { return $this->hasMany(Transformation::class); }
    public function reception(): BelongsTo { return $this->belongsTo(SlaughterReception::class, 'reception_id'); }
    public function ccpRecords(): HasMany { return $this->hasMany(CcpRecord::class); }
    public function byproducts(): HasMany { return $this->hasMany(SlaughterByproduct::class); }
    public function blockedBy(): BelongsTo { return $this->belongsTo(User::class, 'blocked_by_id'); }
    public function releasedBy(): BelongsTo { return $this->belongsTo(User::class, 'released_by_id'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    public function isClosed(): bool { return $this->closed_at !== null; }

    /**
     * Contrôles AUTOMATIQUES de fin de cycle (informatifs) : ce que le système
     * peut vérifier de lui-même avant la clôture. Chaque clé → bool.
     *  - ccp3_recorded : relevé CCP 3 (refroidissement) présent ;
     *  - byproducts_recorded : sous-produits (sang/plumes/viscères) tracés ;
     *  - temperatures_recorded : au moins un relevé de température le jour même.
     */
    public function closureAutoChecks(): array
    {
        $date = $this->actual_date?->toDateString() ?? now()->toDateString();

        return [
            'ccp3_recorded'         => $this->ccpRecords()->where('ccp', CcpRecord::CCP3)->exists(),
            'byproducts_recorded'   => $this->byproducts()->exists(),
            'temperatures_recorded' => \App\Models\TemperatureLog::whereDate('releve_at', $date)->exists(),
        ];
    }

    public function scopePending($query) { return $query->whereIn('status', ['planifie', 'en_cours']); }

    /** RG-03 : un lot bloqué sort du circuit (découpe, stock, vente). */
    public function isBlocked(): bool { return $this->status === 'bloque'; }

    /** RG-07 : façon = produits propriété du client, hors stock vendable. */
    public function isFacon(): bool { return $this->service_type === 'facon'; }

    public function serviceSale(): BelongsTo { return $this->belongsTo(Sale::class, 'service_sale_id'); }

    public function getAvgLiveWeightAttribute(): ?float
    {
        if (! $this->actual_quantity || ! $this->total_live_weight_kg) return null;
        return round($this->total_live_weight_kg / $this->actual_quantity, 3);
    }

    public static function generateNumber(): string
    {
        return \App\Services\DocumentNumberingService::generate('slaughter_order');
    }

    /**
     * Résultat économique du lot d'abattage (marge directe) :
     *
     *  - façon : les produits appartiennent au client (RG-07) — le « produit »
     *    est la PRESTATION facturée (service_fee), sans coût matière ;
     *  - achat : coût = achat vif (reception.purchase_total_cost) ; produit =
     *    valeur des découpes valorisées (Σ quantity_kg × unit_price) ;
     *  - interne : coût d'acquisition suivi au niveau du LOT (P&L) — non
     *    reventilé ici ; on n'affiche que la valeur produite.
     *
     * `has_unpriced` signale des produits de découpe sans prix (valeur
     * partielle — la marge est un plancher, pas un chiffre définitif).
     * Suppose cuttingSessions.products chargés (dossier de lot).
     */
    public function economicSummary(): array
    {
        if ($this->isFacon()) {
            return [
                'mode' => 'facon', 'cost' => 0.0, 'cost_label' => null,
                'output_value' => (float) $this->service_fee,
                'margin' => (float) $this->service_fee, 'has_unpriced' => false,
            ];
        }

        // ─── Coût matière vif : achat OU lot interne (prorata sujets abattus) ───
        $cost = 0.0; $costLabel = null; $mode = 'interne';
        if ($this->reception && $this->reception->origin === 'achat' && $this->reception->purchase_total_cost) {
            $cost = (float) $this->reception->purchase_total_cost;
            $costLabel = 'Achat vif';
            $mode = 'achat';
        } elseif ($this->batch) {
            $qty = (int) ($this->actual_quantity ?: $this->planned_quantity);
            $perBird = (float) ($this->batch->buy_price_per_unit ?? 0);
            if ($perBird <= 0) {
                $init = (int) ($this->batch->initial_quantity ?? 0);
                $perBird = $init > 0 ? (float) $this->batch->total_acquisition_cost / $init : 0;
            }
            $cost = round($perBird * $qty, 2);
            $costLabel = 'Coût du lot (acquisition)';
        }

        $carcassKg = (float) ($this->result?->total_carcass_weight_kg ?? 0);
        $costPerKg = $carcassKg > 0 ? $cost / $carcassKg : 0.0;

        // ─── Découpes : valeur (prix × kg) et carcasse consommée (kg entrés) ───
        $cutValue = 0.0; $cutInputKg = 0.0; $hasUnpriced = false;
        foreach ($this->cuttingSessions as $session) {
            $cutInputKg += (float) $session->total_input_kg;
            foreach ($session->products as $product) {
                $price = (float) ($product->unit_price ?? 0);
                if ($price > 0) $cutValue += (float) $product->quantity_kg * $price;
                else $hasUnpriced = true;
            }
        }
        $cutCost = round($costPerKg * $cutInputKg, 2);

        // ─── Carcasse vendue DIRECTE (kg non découpé : PAC / effilé / reste) ───
        $directKg = max(0.0, $carcassKg - $cutInputKg);
        $directCost = round($costPerKg * $directKg, 2);
        $directValue = 0.0;
        if ($directKg > 0) {
            $carcassName = \App\Services\ButcheryNomenclature::presentationProductName(
                $this->result?->presentation, $this->batch?->species
            );
            $unitPrice = (float) (\App\Models\FinishedProduct::where('product_name', $carcassName)
                ->where('product_type', 'entier_frais')->value('unit_price') ?? 0);
            if ($unitPrice > 0) $directValue = round($unitPrice * $directKg, 2);
            else $hasUnpriced = true;
        }

        // ─── Ventilation par gamme ───
        $gammes = [];
        if ($directKg > 0) {
            $label = \App\Services\ButcheryNomenclature::presentation($this->result?->presentation)['label'] ?? 'Carcasse';
            $gammes[] = ['label' => $label, 'value' => $directValue, 'cost' => $directCost, 'margin' => round($directValue - $directCost, 2)];
        }
        if ($cutInputKg > 0) {
            $gammes[] = ['label' => 'Découpes', 'value' => $cutValue, 'cost' => $cutCost, 'margin' => round($cutValue - $cutCost, 2)];
        }

        $outputValue = round($directValue + $cutValue, 2);

        return [
            'mode' => $mode, 'cost' => round($cost, 2), 'cost_label' => $costLabel,
            'cost_per_kg' => round($costPerKg, 2),
            'output_value' => $outputValue, 'margin' => round($outputValue - $cost, 2),
            'has_unpriced' => $hasUnpriced,
            'gammes' => $gammes,
        ];
    }
}
