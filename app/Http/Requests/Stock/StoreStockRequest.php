<?php

namespace App\Http\Requests\Stock;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('logistique.C'); // ST-06 corrigé : Sécurité à la création
    }

    public function rules(): array
    {
        return [
            'item_name'        => 'required|string|max:255',
            // Catégories multiespèces : lait (laiterie) et produits_finis
            // (viande/carcasse/poisson) en plus des œufs/aliment/litières/matériels.
            'category'         => 'required|in:oeufs,conso,litieres,materiels,lait,produits_finis',
            'unit'             => 'required|string',
            'alert_threshold'  => 'required|numeric|min:0',
            'current_quantity' => 'nullable|numeric|min:0',
            'unit_price'       => 'nullable|numeric|min:0',
            'expiry_date'      => 'nullable|date',
            'lot_number'       => 'nullable|string|max:100',
            'metadata'         => 'nullable|array',
            'metadata.*'       => 'nullable|string', // STM-01 : Validation du contenu JSON
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $itemName = trim($this->item_name);
            if (Stock::where('category', $this->category)->where('item_name', $itemName)->exists()) {
                $validator->errors()->add('item_name', "L'article '{$itemName}' existe déjà dans cette catégorie.");
            }
        });
    }
}