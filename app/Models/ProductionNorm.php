<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductionNorm extends Model
{
    use HasFactory;

    protected $fillable = [
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