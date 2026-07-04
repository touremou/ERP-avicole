<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre des capteurs IoT (capteur → bâtiment).
 *
 * Volontairement SANS FarmScope : table technique alimentée par une API
 * stateless (pas de session ferme) — le farm_id est posé explicitement
 * depuis le bâtiment. L'affectation des capteurs se gère côté admin
 * (base/console) tant que le matériel n'est pas choisi.
 */
class TelemetrySensor extends Model
{
    protected $fillable = [
        'farm_id', 'sensor_id', 'building_id', 'label', 'is_active', 'last_seen_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
}
