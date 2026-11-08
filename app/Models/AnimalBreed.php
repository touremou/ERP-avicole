<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * SOUCHE / RACE d'une espèce — paramétrée par l'administrateur
 * (Administration › Taxonomie animale), proposée à la création d'un lot au
 * bureau comme au terrain (synchro `animal_breeds`, disponible hors ligne).
 *
 * Le lot garde aussi le NOM de sa souche (batches.model_name) : les courbes de
 * référence (ProductionNorm) s'y rattachent. Une souche sans courbe — une race
 * locale — reste choisissable ; elle n'a simplement pas d'objectif chiffré.
 */
class AnimalBreed extends Model
{
    protected $fillable = ['species_id', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class, 'breed_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * LA souche d'un lot, d'après ce que la saisie fournit — déclaration
     * unique, lue par la création et la modification (bureau, terrain).
     *
     *   • `breed_id` fait foi : il doit appartenir à l'espèce du lot ;
     *   • à défaut, un ancien formulaire (ou le hors-ligne du bureau) envoie le
     *     NOM : on le rattache à la souche de même nom de l'espèce, s'il existe.
     *
     * @return array{breed_id: ?int, model_name: ?string}  null = rien à changer
     */
    public static function pourLeLot(?int $breedId, ?string $nom, ?int $especeId): array
    {
        if ($breedId) {
            $souche = self::find($breedId);
            if (! $souche || ($especeId && (int) $souche->species_id !== $especeId)) {
                throw ValidationException::withMessages([
                    'breed_id' => __("Cette souche n'appartient pas à l'espèce du lot."),
                ]);
            }

            return ['breed_id' => $souche->id, 'model_name' => $souche->name];
        }

        $nom = trim((string) $nom);
        if ($nom === '' || ! $especeId) {
            return ['breed_id' => null, 'model_name' => $nom !== '' ? $nom : null];
        }

        $souche = self::where('species_id', $especeId)->where('name', $nom)->first();

        return ['breed_id' => $souche?->id, 'model_name' => $nom];
    }
}
