<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trace d'une opération de synchro qui n'a laissé AUCUN document derrière elle.
 *
 * La synchro terrain est idempotente par `uuid`, et chaque gardien le cherche
 * dans la ligne que l'opération a créée. Une opération qui réussit sans rien
 * créer n'inscrit donc son uuid nulle part, et un rejeu la ré-exécute.
 *
 * Ce registre leur donne un endroit où exister. Il ne remplace pas les uuid
 * portés par les documents — il complète, pour les seuls cas sans document.
 *
 * PAS de FarmScope : c'est un registre d'infrastructure, pas une donnée
 * d'exploitation. Le cadrage par ferme est déjà fait en amont, sur la pièce
 * visée par l'opération (`farmScopedExists`).
 */
class SyncOperation extends Model
{
    protected $fillable = ['type', 'uuid'];

    /** Cette opération a-t-elle déjà été appliquée ? */
    public static function alreadyApplied(string $type, string $uuid): bool
    {
        return static::where('type', $type)->where('uuid', $uuid)->exists();
    }

    /** Inscrit l'opération. Idempotent : un rejeu n'ajoute pas de doublon. */
    public static function remember(string $type, string $uuid): void
    {
        static::firstOrCreate(['type' => $type, 'uuid' => $uuid]);
    }
}
