<?php

namespace App\Http\Requests\EggMovement;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation d'une sortie de stock d'œufs.
 * Vérifie la disponibilité physique avant d'accepter la requête.
 */
class StoreEggMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production.C');
    }

    public function rules(): array
    {
        return [
            'type'     => 'required|in:vente,don,ajustement,casse_magasin',
            'grade'    => 'required|in:XL,L,M,S',
            'quantity' => 'required|integer|min:1',
            'notes'    => 'nullable|string|max:500',
        ];
    }

    /**
     * Vérifie que le stock disponible est suffisant.
     * quantity est en Unités — le stock est en Alvéoles (÷ 30).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $stock = Stock::where('item_name', $this->grade)
                ->where('category', 'oeufs')
                ->first();

            if (! $stock) {
                $validator->errors()->add('grade', "L'article '{$this->grade}' n'existe pas dans le stock.");
                return;
            }

            $requestedAlv = (float) $this->quantity / setting('general.eggs_per_tray', 30);

            if ($stock->current_quantity < $requestedAlv) {
                $available = number_format($stock->current_quantity * setting('general.eggs_per_tray', 30), 0);
                $validator->errors()->add(
                    'quantity',
                    "Stock insuffisant : demandé {$this->quantity} unités, "
                    . "disponible {$available} unités ({$stock->current_quantity} alvéoles)."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'type.in'      => 'Type invalide. Valeurs acceptées : vente, don, ajustement, casse_magasin.',
            'grade.in'     => 'Calibre invalide. Valeurs acceptées : XL, L, M, S.',
            'quantity.min' => 'La quantité doit être d\'au moins 1 unité.',
        ];
    }
}
