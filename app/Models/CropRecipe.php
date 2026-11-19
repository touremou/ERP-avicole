<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Recette de transformation (module Production Végétale).
 *
 * Standardise une opération d'agro-transformation (intrants, produit fini,
 * rendement de référence, conservation). Pendant végétal d'une `Formula`.
 */
class CropRecipe extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'code', 'name', 'transformation_type',
        'output_product', 'output_unit', 'expected_yield_percent',
        'shelf_life_days', 'estimated_cost', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_synced'              => 'boolean',
        'last_sync_at'           => 'datetime',
        'is_active'              => 'boolean',
        'shelf_life_days'        => 'integer',
        'expected_yield_percent' => 'decimal:2',
        'estimated_cost'         => 'decimal:2',
    ];

    // ─── RELATIONS ───

    public function items(): HasMany
    {
        return $this->hasMany(CropRecipeItem::class);
    }

    public function transformations(): HasMany
    {
        return $this->hasMany(CropTransformation::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return CropTransformation::TYPES[$this->transformation_type]
            ?? ucfirst((string) $this->transformation_type);
    }

    /** Quantité totale d'intrants de la recette (référence pour un lot). */
    public function getTotalInputQuantityAttribute(): float
    {
        return (float) ($this->relationLoaded('items')
            ? $this->items->sum('quantity')
            : $this->items()->sum('quantity'));
    }

    // ─── CULTURE TRANSFORMÉE ───

    /** Intrants de la recette (noms libres : « Manioc frais », « Maïs grain »). */
    public function intrants(): array
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->pluck('input_product')->filter()->values()->all();
    }

    /**
     * Cette recette transforme-t-elle cette culture ?
     *
     * L'atelier proposait toutes les recettes quelle que soit la récolte —
     * « Gari » pour une récolte de maïs. Les intrants étant des noms libres,
     * on compare les mots à leur racine (4 premières lettres, sans accents) :
     * « Manioc » ↔ « Manioc frais », « Maïs » ↔ « Farine de mais »,
     * « Palmier à huile » ↔ « Régimes de palme ». Une recette sans intrant
     * déclaré convient à tout (rien pour en juger). Miroir terrain :
     * mobile/src/offline/eligibilite.ts (recetteConvient).
     */
    public function convientA(?string $culture): bool
    {
        $intrants = $this->intrants();
        if ($intrants === [] || blank($culture)) {
            return true;
        }

        $racines = static::racines($culture);
        foreach ($intrants as $intrant) {
            foreach (static::racines($intrant) as $racine) {
                if (in_array($racine, $racines, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Racines des mots d'au moins 3 lettres (4 premières lettres, sans accents). */
    public static function racines(string $texte): array
    {
        $plat = mb_strtolower(\Illuminate\Support\Str::ascii($texte));
        $mots = preg_split('/[^a-z0-9]+/', $plat, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map(
            fn (string $mot) => substr($mot, 0, 4),
            array_filter($mots, fn (string $mot) => strlen($mot) >= 3),
        )));
    }
}
