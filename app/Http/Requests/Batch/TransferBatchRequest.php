<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
        return [
            'target_building_id' => 'required|integer|exists:buildings,id',
            'new_protocol_id'    => 'required|integer|exists:protocols,id',
            'new_phase'          => 'required|in:poussiniere,chair,ponte,reproducteur',
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
            if ($batch->status !== 'Actif') {
                $validator->errors()->add('status', "Le lot {$batch->code} n'est pas actif (statut : {$batch->status}). Transfert impossible.");
                return;
            }

            $targetBuilding = Building::find($this->input('target_building_id'));
            if (! $targetBuilding) {
                return; // L'erreur 'exists' couvre déjà
            }

            // Compatibilité de type (bâtiment mixte accepte tout)
            if ($targetBuilding->type !== $batch->type && $targetBuilding->type !== 'mixte') {
                $validator->errors()->add(
                    'target_building_id',
                    "Incompatibilité : lot de type '{$batch->type}', bâtiment de type '{$targetBuilding->type}'."
                );
            }

            // Capacité
            $currentOccupation = Batch::where('building_id', $targetBuilding->id)
                ->where('status', 'Actif')
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
