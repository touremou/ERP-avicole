<?php

namespace App\Models;

use App\Traits\ReferencesEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AFFECTATION D'UN AGENT À UN SITE, entre deux dates.
 *
 * Remplace la déduction implicite « farm_id du dossier + accès du compte », qui
 * n'était pas une décision mais un effet de bord — et qui a fui dans une dizaine
 * d'écrans, chacun redécouvrant la règle à sa façon.
 *
 * PAS de BelongsToFarm : c'est cette table qui DÉFINIT l'appartenance à une
 * ferme. La filtrer par ferme serait circulaire — on ne verrait les affectations
 * d'un site qu'en y étant déjà.
 */
class EmployeeAssignment extends Model
{
    // Le lien vers l'agent passe par la déclaration UNIQUE (cf. le trait) : écrit
    // à la main, il réappliquait le filtre de ferme et renvoyait null pour un
    // agent dont le dossier vit ailleurs — c'est-à-dire pour le cas même que
    // cette table sert à représenter.
    use ReferencesEmployee;

    /**
     * Les deux formes d'affectation. Elles ne diffèrent que par ce qu'elles font
     * du DOSSIER — donc de la paie.
     */
    public const TYPES = [
        'mutation' => [
            'label' => 'Mutation',
            'emoji' => '🔁',
            'help'  => 'Le dossier change de site. La paie suit : le nouveau site paie et évalue.',
        ],
        'mise_a_disposition' => [
            'label' => 'Mise à disposition',
            'emoji' => '🤝',
            'help'  => "L'agent travaille ici, mais son dossier et sa paie restent à son site d'origine.",
        ],
    ];

    protected $fillable = [
        'employee_id', 'farm_id', 'type', 'start_date', 'end_date', 'reason', 'decided_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Affectations COUVRANT une date donnée.
     *
     * `end_date` nulle = toujours en cours : c'est le cas le plus fréquent, et
     * l'oublier ferait disparaître tout le monde. Une affectation commence le
     * jour de sa date de début (bornes incluses des deux côtés).
     */
    public function scopeCoveringDate(Builder $query, $date): Builder
    {
        $day = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $query->whereDate('start_date', '<=', $day)
            ->where(fn ($sub) => $sub->whereNull('end_date')->orWhereDate('end_date', '>=', $day));
    }

    /** L'affectation couvre-t-elle cette date ? */
    public function coversDate($date): bool
    {
        $day = \Illuminate\Support\Carbon::parse($date);

        return $this->start_date->lte($day)
            && ($this->end_date === null || $this->end_date->gte($day));
    }

    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type]['label'] ?? $this->type);
    }

    public function typeEmoji(): string
    {
        return self::TYPES[$this->type]['emoji'] ?? '📍';
    }

    /** Options du menu déroulant : [clef => « emoji Libellé »]. */
    public static function typeOptions(): array
    {
        $options = [];

        foreach (self::TYPES as $key => $meta) {
            $options[$key] = $meta['emoji'] . ' ' . __($meta['label']);
        }

        return $options;
    }
}
