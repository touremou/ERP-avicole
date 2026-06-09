<?php

namespace App\Http\Requests\Stock;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('stocks.C'); // ST-06 corrigé : Sécurité à la création
    }

    public function rules(): array
    {
        return [
            'item_name'        => 'required|string|max:255',
            'category'         => 'required|in:oeufs,conso,litieres,materiels',
            'unit'             => 'required|string',
            'alert_threshold'  => 'required|numeric|min:0',
            'current_quantity' => 'nullable|numeric|min:0',
            'unit_price'       => 'nullable|numeric|min:0',
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