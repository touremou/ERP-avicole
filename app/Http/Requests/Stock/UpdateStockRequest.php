<?php

namespace App\Http\Requests\Stock;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('logistique.M');
    }

    public function rules(): array
    {
        return [
            'item_name'        => 'required|string|max:255',
            'unit'             => 'required|string',
            'alert_threshold'  => 'required|numeric|min:0',
            'current_quantity' => 'required|numeric', 
            'unit_price'       => 'nullable|numeric|min:0',
            'expiry_date'      => 'nullable|date',
            'lot_number'       => 'nullable|string|max:100',
            'metadata'         => 'nullable|array',
            // Teneur en matière sèche (%) : l'autonomie fourragère du troupeau.
            'metadata.dry_matter_pct' => 'nullable|numeric|min:1|max:100',
            // Nature (ensilage, foin…) : la teneur par défaut, à défaut de saisie.
            'metadata.forage_nature' => 'nullable|in:' . implode(',', array_keys(\App\Models\Stock::NATURES_FOURRAGERES)),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $stockId = $this->route('id');
            $currentStock = Stock::findOrFail($stockId);
            $itemName = trim($this->item_name);

            if ($itemName !== $currentStock->item_name) {
                $exists = Stock::where('category', $currentStock->category)
                    ->where('item_name', $itemName)
                    ->where('id', '!=', $stockId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('item_name', "Un autre article porte déjà ce nom.");
                }
            }
        });
    }
}