<?php

namespace App\Traits;

use App\Models\Farm;
use App\Scopes\FarmScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait BelongsToFarm
 *
 * Ajoute automatiquement le filtrage par ferme à tout model qui l'utilise.
 *
 * UTILISATION :
 *   class Batch extends Model {
 *       use BelongsToFarm;
 *   }
 *
 * EFFET :
 *   Batch::all()           → SELECT * FROM batches WHERE farm_id = {current_farm_id}
 *   Batch::create([...])   → INSERT avec farm_id automatique
 *   Batch::withoutFarm()   → Désactive le scope (pour le reporting cross-fermes)
 *
 * IMPORTANT :
 * - Le scope s'active UNIQUEMENT si une ferme courante est définie en session
 * - En l'absence de ferme courante, aucun filtre n'est appliqué (rétrocompatible)
 * - Les propriétaires peuvent utiliser withoutFarm() pour voir toutes les fermes
 */
trait BelongsToFarm
{
    /**
     * Boot du trait : enregistre le global scope + auto-fill farm_id à la création.
     */
    public static function bootBelongsToFarm(): void
    {
        // ─── GLOBAL SCOPE : filtre automatique par ferme ───
        static::addGlobalScope(new FarmScope());

        // ─── AUTO-FILL : assigner farm_id à la création ───
        // On privilégie la ferme courante (session) ; en son absence (seeder,
        // factory, console, ou tout contexte hors HTTP) on retombe sur la ferme
        // par défaut afin de ne JAMAIS créer d'enregistrement orphelin
        // (farm_id NULL) — sinon le décompte par ferme (cf. Multi-Sites) ignore
        // ces enregistrements puisqu'il filtre strictement sur farm_id.
        static::creating(function ($model) {
            if (empty($model->farm_id) && \Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'farm_id')) {
                $model->farm_id = session('current_farm_id') ?: Farm::defaultId();
            }
        });
    }

    /**
     * Relation vers la ferme propriétaire.
     */
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Scope pour désactiver le filtre ferme (reporting cross-fermes).
     *
     * Usage : Batch::withoutFarm()->count()
     */
    public function scopeWithoutFarm($query)
    {
        return $query->withoutGlobalScope(FarmScope::class);
    }

    /**
     * Scope pour filtrer par une ferme spécifique.
     *
     * Usage : Batch::forFarm(2)->get()
     */
    public function scopeForFarm($query, int $farmId)
    {
        return $query->withoutGlobalScope(FarmScope::class)
            ->where('farm_id', $farmId);
    }
}
