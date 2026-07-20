<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sous-produit d'abattage (E9) — sang, plumes, viscères : volume et
 * destination tracés, INSERT-ONLY comme tout registre sanitaire.
 */
class SlaughterByproduct extends Model
{
    use BelongsToFarm;

    public const TYPES = [
        'sang'      => 'Sang',
        'plumes'    => 'Plumes',
        'visceres'  => 'Viscères non comestibles',
        'autre'     => 'Autre',
    ];

    public const DESTINATIONS = [
        'equarrissage' => 'Équarrissage',
        'vente'        => 'Vente (valorisation)',
        'compost'      => 'Compost',
        'dechets'      => 'Déchets',
        'autre'        => 'Autre',
    ];

    /** Méthodes de quantification (E9) : pesée réelle ou estimation par ratio. */
    public const METHODS = [
        'pese'   => 'Pesé',
        'estime' => 'Estimé (ratio)',
    ];

    protected $fillable = [
        'farm_id', 'slaughter_order_id', 'type', 'quantity_kg', 'method',
        'destination', 'notes', 'operator_id', 'collected_at', 'synced_at',
    ];

    protected $casts = [
        'quantity_kg'  => 'decimal:2',
        'collected_at' => 'datetime',
        'synced_at'    => 'datetime',
    ];

    public function slaughterOrder(): BelongsTo
    {
        return $this->belongsTo(SlaughterOrder::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
