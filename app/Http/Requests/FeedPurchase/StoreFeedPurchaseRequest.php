<?php

namespace App\Http\Requests\FeedPurchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreFeedPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        return [
            'batch_id'      => 'required|exists:batches,id',
            'purchase_date' => 'required|date|before_or_equal:today',
            'feed_type'     => 'required|string|max:255',
            'quantity'      => 'required|numeric|min:0.001',
            'unit_price'    => 'required|numeric|min:0', // Montant total payé
            'supplier'      => 'nullable|string|max:255',
            'unit'          => 'required|string|in:Sac,KG,Litre,Unité,Boite',
            'metadata'      => 'nullable|array',
        ];
    }
}