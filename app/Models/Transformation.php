<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class Transformation extends Model
{
    use BelongsToFarm;

    /**
     * PROCÉDÉS DE TRANSFORMATION — déclaration unique.
     *
     * La liste vivait en trois exemplaires : les <option> du formulaire, le
     * `match` de getTypeLabelAttribute() et la règle `in:fume,grille,marine,autre`
     * du contrôleur. Chaque procédé porte aussi la clef du réglage qui fixe SON
     * rendement cible : l'écran de saisie comparait le rendement d'une marinade
     * à la cible de FUMAGE, et à une cible « carcasse » qui n'a jamais existé
     * dans la table des réglages.
     */
    public const TYPES = [
        'fume'   => ['label' => 'Fumé',    'setting' => 'abattoir.yield_smoking'],
        'grille' => ['label' => 'Grillé',  'setting' => 'abattoir.yield_grille'],
        'marine' => ['label' => 'Mariné',  'setting' => 'abattoir.yield_marine'],
        'autre'  => ['label' => 'Autre',   'setting' => 'abattoir.yield_autre'],
    ];

    /** Libellés des procédés, pour les formulaires et les listes. */
    public static function typeLabels(): array
    {
        return array_map(fn ($type) => $type['label'], self::TYPES);
    }

    /**
     * Rendement cible de chaque procédé, lu au paramétrage.
     *
     * `null` signifie « aucune cible fixée » — et l'écran le DIT, au lieu de
     * juger la marinade à l'aune du fumage. Une cible absente n'est pas une
     * cible à deviner.
     */
    public static function yieldTargets(): array
    {
        $targets = [];

        foreach (self::TYPES as $slug => $type) {
            $value = setting($type['setting']);
            $targets[$slug] = ($value === null || $value === '') ? null : (float) $value;
        }

        return $targets;
    }

    protected $fillable = [
        'farm_id', 'slaughter_order_id',
        'batch_number', 'product_source', 'transformation_type',
        'input_kg', 'output_kg', 'yield_percent',
        'production_date', 'expiry_date',
        'operator_id', 'production_cost', 'source_unit_cost', 'status', 'notes',
    ];

    protected $casts = [
        'input_kg'        => 'decimal:2',
        'output_kg'       => 'decimal:2',
        'yield_percent'   => 'decimal:2',
        'production_date' => 'date',
        'expiry_date'     => 'date',
        'production_cost' => 'decimal:2',
        'source_unit_cost' => 'decimal:2',
    ];

    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }

    /** Ordre d'abattage d'origine (traçabilité en cascade) — nullable. */
    public function slaughterOrder(): BelongsTo { return $this->belongsTo(SlaughterOrder::class); }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->transformation_type]['label']
            ?? ucfirst((string) $this->transformation_type);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public static function generateBatchNumber(): string
    {
        return \App\Services\DocumentNumberingService::generate('transformation');
    }
}
