<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Models\Species;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation pour le transfert d'un lot vers un autre bâtiment.
 *
 * Intègre les vérifications métier qui étaient dans le controller :
 * - Compatibilité de type bâtiment/lot
 * - Capacité disponible
 * - Anti auto-transfert
 * - Lot actif uniquement
 *
 * Corrige S-09 (pas de verrou métier sur lot Terminé).
 */
class TransferBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.M');
    }

    public function rules(): array
    {
        // Phases cibles autorisées = types de production réels de l'espèce du
        // lot (source de vérité), + phases volailles historiques, + phase/type
        // courant. Une poussinière peut ainsi graduer vers chair/ponte/repro.
        $allowedPhases = ['poussiniere', 'chair', 'ponte', 'reproducteur'];

        $batch = $this->route('batch');
        if (! $batch instanceof Batch) {
            $batch = Batch::find($batch);
        }

        if ($batch) {
            $speciesSlugs = ProductionType::where('species_id', $batch->species_id)
                ->pluck('slug')->all();

            foreach (array_merge($speciesSlugs, [$batch->production_phase, $batch->type]) as $value) {
                if ($value && ! in_array($value, $allowedPhases, true)) {
                    $allowedPhases[] = $value;
                }
            }
        }

        return [
            'target_building_id' => 'required|integer|exists:buildings,id',
            'new_protocol_id'    => 'required|integer|exists:protocols,id',
            'new_phase'          => ['required', Rule::in($allowedPhases)],
            'transfer_date'      => 'required|date',
            'notes'              => 'nullable|string|max:1000',
        ];
    }

    /**
     * Validations métier après les règles de base.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $batch = $this->route('batch');
            if (! $batch instanceof Batch) {
                $batch = Batch::find($batch);
            }

            if (! $batch) {
                $validator->errors()->add('batch', 'Lot introuvable.');
                return;
            }

            // S-09 : Lot doit être actif
            if (! $batch->isActive()) {
                $validator->errors()->add('status', "Le lot {$batch->code} n'est pas actif (statut : {$batch->status}). Transfert impossible.");
                return;
            }

            $targetBuilding = Building::find($this->input('target_building_id'));
            if (! $targetBuilding) {
                return; // L'erreur 'exists' couvre déjà
            }

            // Compatibilité de type (bâtiment mixte accepte tout).
            // On compare au type CIBLE (new_phase) : une mutation peut faire
            // graduer un lot (ex. poussinière -> ponte/reproducteur après
            // éclosion), le bâtiment de destination doit alors correspondre
            // au NOUVEAU type, pas à l'ancien.
            $targetType = $this->input('new_phase') ?: $batch->type;

            // Espèces non-avicoles : l'habitat est dédié à l'ESPÈCE (ex.
            // chèvrerie), pas à la phase de production (engraissement,
            // laitière...). On compare donc au référentiel d'habitats
            // compatibles (config/livestock.php) plutôt qu'au slug de phase.
            if (! Species::buildingIsCompatible($targetBuilding, $batch->species, $targetType)) {
                $validator->errors()->add(
                    'target_building_id',
                    "Incompatibilité : type cible '{$targetType}', bâtiment de type '{$targetBuilding->type}'."
                );
            }

            // Capacité — on exclut toujours le lot lui-même de l'occupation
            // (sinon une transformation sur place compterait ses propres
            // sujets contre la place disponible).
            $currentOccupation = Batch::where('building_id', $targetBuilding->id)
                ->active()
                ->where('id', '!=', $batch->id)
                ->sum('current_quantity');

            $available = $targetBuilding->capacity - $currentOccupation;
            if ($batch->current_quantity > $available) {
                $validator->errors()->add(
                    'target_building_id',
                    "Capacité insuffisante : {$batch->current_quantity} sujets, {$available} places disponibles dans {$targetBuilding->name}."
                );
            }

            // Anti auto-transfert — autorisé SI c'est une transformation sur
            // place (la phase/type change). Rester dans le même bâtiment sans
            // changer de phase n'aurait aucun effet.
            $isGraduation = $targetType && $targetType !== $batch->type;
            if ($batch->building_id == $targetBuilding->id && ! $isGraduation) {
                $validator->errors()->add(
                    'target_building_id',
                    "Le lot {$batch->code} est déjà dans le bâtiment {$targetBuilding->name} et aucun changement de phase n'est demandé."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'target_building_id.required' => 'Le bâtiment cible est requis.',
            'new_protocol_id.required'    => 'Le protocole sanitaire est requis.',
            'new_phase.in'                => 'Phase de production invalide.',
            'transfer_date.required'      => 'La date de transfert est requise.',
        ];
    }
}
