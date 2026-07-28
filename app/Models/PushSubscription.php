<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement d'un APPAREIL au push navigateur.
 *
 * Un utilisateur peut en avoir plusieurs — téléphone de service, téléphone
 * personnel, navigateur du bureau. L'identité de l'abonnement est son `endpoint`,
 * fourni par le navigateur ; on l'indexe par empreinte pour rester sous la limite
 * de clef MySQL tout en garantissant l'unicité.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth',
        'device_label', 'last_success_at', 'failure_count',
    ];

    protected $casts = [
        'last_success_at' => 'datetime',
        'failure_count'   => 'integer',
    ];

    /** Au-delà de ce nombre d'échecs consécutifs, l'appareil est considéré perdu. */
    public const MAX_FAILURES = 5;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Enregistre ou met à jour l'abonnement d'un appareil.
     *
     * Le même appareil qui se réabonne (réinstallation de l'app, rotation de clef)
     * doit METTRE À JOUR sa ligne : deux lignes pour un téléphone lui enverraient
     * deux fois chaque alerte.
     */
    public static function register(int $userId, array $subscription, ?string $deviceLabel = null): self
    {
        return static::updateOrCreate(
            ['endpoint_hash' => self::hashFor($subscription['endpoint'])],
            [
                'user_id'         => $userId,
                'endpoint'        => $subscription['endpoint'],
                'p256dh'          => $subscription['keys']['p256dh'],
                'auth'            => $subscription['keys']['auth'],
                'device_label'    => $deviceLabel,
                'failure_count'   => 0,   // un réabonnement repart de zéro
            ]
        );
    }

    /** Format attendu par la bibliothèque d'envoi. */
    public function toPayload(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys'     => ['p256dh' => $this->p256dh, 'auth' => $this->auth],
        ];
    }
}
