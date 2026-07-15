<?php

namespace App\Http\Requests\Sale;

use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('commerce.C');
    }

    public function rules(): array
    {
        return [
            'sale_id'      => 'required|exists:sales,id',
            'amount'       => 'required|numeric|min:1',
            'payment_date' => 'required|date|before_or_equal:today',
            'method'       => 'required|in:especes,orange_money,virement,cheque',
            'treasury_account_id' => 'nullable|exists:treasury_accounts,id',
            'reference'    => 'nullable|string|max:100',
            'notes'        => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sale = Sale::find($this->sale_id);

            if ($sale && $sale->payment_status === 'solde') {
                $validator->errors()->add('sale_id', "Cette vente est déjà soldée.");
            }

            if ($sale && in_array($sale->status, ['brouillon', 'annule'])) {
                $validator->errors()->add('sale_id', "Impossible d'encaisser sur une vente {$sale->status}.");
            }

            if ($sale && $sale->client && $sale->client->status !== 'actif') {
                $validator->errors()->add('sale_id', "Le client {$sale->client->name} est {$sale->client->status} : encaissement bloqué.");
            }

            if ($sale && $this->amount > $sale->remaining_amount) {
                $validator->errors()->add('amount',
                    "Montant trop élevé. Reste dû : " . number_format($sale->remaining_amount) . " GNF."
                );
            }
        });
    }
}
