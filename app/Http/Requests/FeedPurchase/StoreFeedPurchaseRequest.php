<?php

namespace App\Http\Requests\FeedPurchase;

use App\Models\FeedPurchase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'batch_id'      => ['required', \App\Support\Ferme::existe('batches')],
            'purchase_date' => 'required|date|before_or_equal:today',
            'feed_type'     => 'required|string|max:255',
            'quantity'      => 'required|numeric|min:0.001',
            'unit_price'    => 'required|numeric|min:0', // Montant total payé
            'supplier'      => 'nullable|string|max:255',
            'payment_mode'  => 'nullable|in:comptant,credit',
            // Le compte d'où sort l'argent d'un achat COMPTANT. Facultatif :
            // vide = résolution par le mode de paiement, comme partout ailleurs.
            'treasury_account_id' => 'nullable|exists:treasury_accounts,id',
            'unit'          => ['required', 'string', Rule::in(FeedPurchase::UNITES)],
            // Catégorie et nature : un vaccin acheté ici entrait au stock comme
            // ALIMENT (catégorie et nature n'étaient pas transmises).
            'category'      => ['nullable', Rule::in(FeedPurchase::CATEGORIES)],
            'metadata'      => 'nullable|array',
            'metadata.conso_type' => ['nullable', Rule::in(FeedPurchase::NATURES)],
        ];
    }
}