<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre des températures (E4) — relevés manuels ≥ 2/jour par point de
 * contrôle, INSERT-ONLY. Conformité calculée serveur selon les seuils
 * paramétrables (Réglages > abattoir). Hors seuil → alerte immédiate.
 *
 * Complémentaire de l'ingestion IoT (telemetry) : ici c'est le geste
 * manuel tracé de l'agent, exigé par le plan de maîtrise sanitaire.
 */
class TemperatureLog extends Model
{
    use BelongsToFarm;

    /** Points de contrôle → seuils [min, max] dans les réglages abattoir. */
    public const POINTS = [
        'chambre_froide_positive' => ['min' => 'cold_positive_min', 'max' => 'cold_positive_max'],
        'congelation'             => ['min' => null,                'max' => 'freezer_max'],
        'salle_decoupe'           => ['min' => null,                'max' => 'cutting_room_max'],
        'echaudage'               => ['min' => 'scalding_min',      'max' => 'scalding_max'],
        'vehicule'                => ['min' => null,                'max' => 'vehicle_max'],
    ];

    public const POINT_LABELS = [
        'chambre_froide_positive' => 'Chambre froide positive',
        'congelation'             => 'Congélation',
        'salle_decoupe'           => 'Salle de découpe',
        'echaudage'               => 'Échaudage',
        'vehicule'                => 'Véhicule frigorifique',
    ];

    protected $fillable = [
        'farm_id', 'point', 'equipment_ref', 'temperature', 'conforme',
        'corrective_action', 'operator_id', 'releve_at', 'synced_at',
    ];

    protected $casts = [
        'temperature' => 'decimal:1',
        'conforme'    => 'boolean',
        'releve_at'   => 'datetime',
        'synced_at'   => 'datetime',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** Bornes effectives du point selon les réglages (null = non bornée). */
    public static function boundsFor(string $point): array
    {
        $keys = self::POINTS[$point] ?? ['min' => null, 'max' => null];

        /*
         * UN SEUIL ABSENT EST ABSENT, PAS ZÉRO.
         *
         * Le cast en float transformait une valeur vide en 0.0 — et `isCompliant`,
         * juste en dessous, teste `!== null` pour savoir s'il y a une borne à
         * appliquer. Son chemin « pas de borne » était donc INATTEIGNABLE : un
         * seuil effacé devenait un maximum de 0 °C, et toute chambre froide à 3 °C
         * se lisait non conforme.
         *
         * On ne substitue AUCUNE valeur de repli : les seuils de référence vivent
         * dans la migration qui les sème, et les recopier ici en ferait une seconde
         * déclaration — celle qui finit par diverger. Faute de seuil, on n'en
         * applique pas : l'appréciation de l'opérateur fait foi.
         *
         * `0` déclaré reste un vrai seuil : c'est le minimum de la chambre froide
         * positive.
         */
        return [
            'min' => self::threshold($keys['min']),
            'max' => self::threshold($keys['max']),
        ];
    }

    /** Le seuil paramétré, ou null s'il n'est pas renseigné. */
    private static function threshold(?string $key): ?float
    {
        if (! $key) {
            return null;
        }

        // `rawValue` et non `setting()` : le cast d'un réglage numérique
        // transforme la chaîne vide en 0, ce qui est exactement la confusion à
        // lever. Le modèle Setting porte cette méthode pour cette raison —
        // « la seule façon de distinguer pas de réglage de réglé à zéro ».
        $value = \App\Models\Setting::rawValue("abattoir.{$key}");

        return ($value === null || $value === '') ? null : (float) $value;
    }

    /** Conformité serveur — source unique, jamais confiée au client. */
    public static function isCompliant(string $point, float $temperature): bool
    {
        $bounds = self::boundsFor($point);

        if ($bounds['min'] !== null && $temperature < $bounds['min']) {
            return false;
        }

        return ! ($bounds['max'] !== null && $temperature > $bounds['max']);
    }
}
