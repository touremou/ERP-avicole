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
            'metadata'         => 'nullable|array',
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