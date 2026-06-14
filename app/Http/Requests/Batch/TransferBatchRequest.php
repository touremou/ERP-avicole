<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use App\Models\Building;
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
        // Phases historiques (volaille). Pour les espèces non-volailles
        // (ruminants, aquaculture, ...), la mutation ne change pas la phase
        // de production : on autorise donc aussi la phase/le type courant
        // du lot, déjà sélectionné comme seule option dans le formulaire.
        $allowedPhases = ['poussiniere', 'chair', 'ponte', 'reproducteur'];

        $batch = $this->route('batch');
        if (! $batch instanceof Batch) {
            $batch = Batch::find($batch);
        }

        if ($batch) {
            foreach ([$batch->production_phase, $batch->type] as $value) {
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
            if ($targetBuilding->type !== $targetType && $targetBuilding->type !== 'mixte') {
                $validator->errors()->add(
                    'target_building_id',
                    "Incompatibilité : type cible '{$targetType}', bâtiment de type '{$targetBuilding->type}'."
                );
            }

            // Capacité
            $currentOccupation = Batch::where('building_id', $targetBuilding->id)
                ->active()
                ->sum('current_quantity');

            $available = $targetBuilding->capacity - $currentOccupation;
            if ($batch->current_quantity > $available) {
                $validator->errors()->add(
                    'target_building_id',
                    "Capacité insuffisante : {$batch->current_quantity} sujets, {$available} places disponibles dans {$targetBuilding->name}."
                );
            }

            // Anti auto-transfert
            if ($batch->building_id == $targetBuilding->id) {
                $validator->errors()->add(
                    'target_building_id',
                    "Le lot {$batch->code} est déjà dans le bâtiment {$targetBuilding->name}."
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
