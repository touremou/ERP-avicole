<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionNorm extends Model
{
    use HasFactory;

    protected $fillable = [
        'species_id',  // Espèce rattachée (null = souche générique, toutes espèces)
        'batch_type',
        'week_number',
        'phase_name',
        'model_name', // Ajout crucial pour identifier la souche (Ross, ISA, etc.)
        'target_weight',
        'target_feed_daily',
        'target_water_daily',
        'target_laying_rate'
    ];

    /**
     * Espèce à laquelle la souche est rattachée (null = générique).
     */
    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /**
     * Limite les souches à une espèce donnée. Les souches génériques
     * (species_id NULL) restent visibles pour toutes les espèces.
     */
    public function scopeForSpecies($query, $speciesId)
    {
        return $query->where(function ($q) use ($speciesId) {
            $q->whereNull('species_id')->orWhere('species_id', $speciesId);
        });
    }

    /**
     * Déduit le slug d'espèce d'un nom de souche par mots-clés.
     * Utilisé pour le backfill (migration) et le rattachement au seed.
     * Retourne null si aucune correspondance fiable (souche générique).
     */
    public static function guessSpeciesSlug(?string $modelName): ?string
    {
        $name = mb_strtolower((string) $modelName);

        // Ordre important : tester les espèces avant les mots ambigus.
        $map = [
            'dinde'   => ['dinde', 'dindon', 'but 6'],
            'caille'  => ['caille'],
            'pintade' => ['pintade'],
            'canard'  => ['canard'],
            'pigeon'  => ['pigeon', 'goliath'],
            'poulet'  => ['poule', 'poulet', 'ross', 'cobb', 'isa', 'lohmann', 'pondeuse', 'cou nu'],
            'mouton'  => ['mouton', 'bélier', 'belier', 'djallonké', 'djallonke'],
            'chevre'  => ['chèvre', 'chevre', 'bouc', 'maradi', 'saanen', 'sahel'],
            'lapin'   => ['lapin'],
            'porc'    => ['porc', 'large white'],
            'tilapia' => ['tilapia', 'alevin'],
            'carpe'   => ['carpe'],
            'silure'  => ['silure', 'clarias'],
            'vache'   => ['vache', 'bovin', 'zébu', 'zebu', 'ndama', 'n\'dama'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $kw) {
                if ($name !== '' && str_contains($name, $kw)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * Liste des types pour l'interface (utilisée dans les selects/filtres)
     */
    public static function types()
    {
        return ['chair', 'ponte', 'repro', 'poussiniere'];
    }

    /**
     * Scope pour filtrer par type
     * Usage : ProductionNorm::byType('chair')->get()
     */
    public function scopeByType($query, $type)
    {
        return $query->where('batch_type', $type);
    }

    /**
     * Récupère la norme spécifique pour un âge donné (en jours)
     * Calcule automatiquement la semaine correspondante.
     */
    public static function getNormForAge($type, $ageInDays)
    {
        // On s'assure que la semaine commence à 1
        $week = max(1, ceil($ageInDays / 7));
        
        return self::where('batch_type', $type)
                   ->where('week_number', $week)
                   ->first();
    }
}