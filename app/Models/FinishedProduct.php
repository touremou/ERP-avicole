<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToFarm;

class FinishedProduct extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'product_name', 'product_type',
        'current_quantity_kg', 'current_quantity_pieces', 'unit',
        'unit_price', 'unit_cost', 'storage_location', 'expiry_date',
        'alert_threshold_kg', 'batch_reference',
    ];

    protected $casts = [
        'current_quantity_kg'     => 'decimal:2',
        'unit_price'              => 'decimal:2',
        'unit_cost'               => 'decimal:2',
        'alert_threshold_kg'      => 'decimal:2',
        'expiry_date'             => 'date',
    ];

    public function scopeLowStock($query)
    {
        return $query->where('alert_threshold_kg', '>', 0)
            ->whereRaw('current_quantity_kg <= alert_threshold_kg');
    }

    public function scopeExpiringSoon($query, int $days = 3)
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('current_quantity_kg', '>', 0);
    }

    public function getIsLowAttribute(): bool
    {
        return $this->alert_threshold_kg > 0 && $this->current_quantity_kg <= $this->alert_threshold_kg;
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->diffInDays(now()) <= 3 && ! $this->expiry_date->isPast();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function getTypeLabelAttribute(): string
    {
        // Libellés transverses (état/transformation) — indépendants de l'espèce.
        $common = [
            'entier_frais'   => 'Entier Frais',
            'entier_congele' => 'Entier Congelé',
            'fume'           => 'Fumé',
            'grille'         => 'Grillé',
            'marine'         => 'Mariné',
        ];

        if (isset($common[$this->product_type])) {
            return $common[$this->product_type];
        }

        // Morceaux de découpe multiespèces : libellé issu de la nomenclature
        // (config/butchery.php), toutes familles confondues.
        foreach ((array) config('butchery.cuts', []) as $cuts) {
            foreach ($cuts as $cut) {
                if (($cut['code'] ?? null) === $this->product_type) {
                    return $cut['label'];
                }
            }
        }

        return ucfirst((string) $this->product_type);
    }
}
