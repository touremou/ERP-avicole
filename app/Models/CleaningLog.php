<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre nettoyage / désinfection (E7) — trace des opérations du plan de
 * nettoyage (zone, produit agréé, dosage), INSERT-ONLY. Premier rempart
 * sanitaire de l'abattoir : le plan écrit est affiché, l'exécution est ici.
 */
class CleaningLog extends Model
{
    use BelongsToFarm;

    /** Zones du plan de nettoyage (tableau 18 du dossier projet). */
    public const ZONES = [
        'surfaces_tables'  => 'Surfaces et tables de travail',
        'sols_siphons'     => 'Sols et siphons',
        'couteaux_materiel' => 'Couteaux et petit matériel',
        'chambre_froide'   => 'Chambres froides',
        'vehicule'         => 'Véhicules frigorifiques',
        'zone_dechets'     => 'Zone déchets / bacs',
        'autre'            => 'Autre',
    ];

    protected $fillable = [
        'farm_id', 'zone', 'product_used', 'dosage', 'notes',
        'photo_path', 'operator_id', 'done_at', 'synced_at',
    ];

    protected $casts = [
        'done_at'   => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
