<?php

namespace App\Http\Requests\Stock;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MoveStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('stocks.M');
    }

    public function rules(): array
    {
        return [
            'stock_id' => 'required|exists:stocks,id',
            'type'     => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0.001',
            'notes'    => 'nullable|string|max:500'
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->type === 'out') {
                $stock = Stock::find($this->stock_id);
                if ($stock && $stock->current_quantity < $this->quantity) {
                    $validator->errors()->add('quantity', "Stock insuffisant (Disponible: {$stock->current_quantity} {$stock->unit}).");
                }
            }
        });
    }
}