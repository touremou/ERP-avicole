<?php

namespace App\Http\Requests\FeedPurchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateFeedPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('M');
    }

    public function rules(): array
    {
        return [
            'feed_type'     => 'required|string|max:255',
            'quantity'      => 'required|numeric|min:0.001',
            'unit_price'    => 'required|numeric|min:0', // Montant total payé
            'supplier'      => 'nullable|string|max:255',
            'purchase_date' => 'required|date',
            'metadata'      => 'nullable|array',
        ];
    }
}