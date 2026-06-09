<?php

namespace App\Http\Requests\Sale;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        return [
            'client_id'              => 'required|exists:clients,id',
            'sale_date'              => 'required|date|before_or_equal:today',
            'type'                   => 'required|in:bon_livraison,facture',
            'tax_rate'               => 'nullable|numeric|in:0,18',
            'delivery_mode'          => 'nullable|in:sur_place,livraison',
            'delivery_address'       => 'nullable|string|max:500',
            'delivery_notes'         => 'nullable|string|max:500',
            'notes'                  => 'nullable|string|max:2000',

            // Lignes de vente
            'items'                  => 'required|array|min:1',
            'items.*.product_type'   => 'required|in:oeufs,volaille_vivante,volaille_abattue,fumier,aliment,materiel,autre',
            'items.*.product_name'   => 'required|string|max:255',
            'items.*.product_id'     => 'nullable|integer',
            'items.*.batch_id'       => 'nullable|integer|exists:batches,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|in:alveole,unite,kg,piece,sac,voyage',
            'items.*.unit_price'     => 'required|numeric|min:0',

            // Paiement immédiat (optionnel)
            'immediate_payment'      => 'nullable|numeric|min:0',
            'payment_method'         => 'nullable|in:especes,orange_money,virement,cheque',
            'payment_reference'      => 'nullable|string|max:100',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Vérifier que le client est actif
            $client = Client::find($this->client_id);
            if ($client && $client->status !== 'actif') {
                $validator->errors()->add('client_id', "Le client {$client->name} est {$client->status}.");
            }

            // Vérifier le plafond crédit si pas de paiement immédiat total
            if ($client && $client->credit_limit > 0) {
                $total = collect($this->items)->sum(fn($i) => ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0));
                $immediatePayment = $this->immediate_payment ?? 0;
                $newCredit = $total - $immediatePayment;

                if ($newCredit > 0 && ($client->balance + $newCredit) > $client->credit_limit) {
                    $validator->errors()->add('client_id',
                        "Plafond crédit dépassé pour {$client->name}. " .
                        "Solde actuel : " . number_format($client->balance) . " GNF, " .
                        "Plafond : " . number_format($client->credit_limit) . " GNF."
                    );
                }
            }

            // Au moins une ligne doit avoir un montant > 0
            $hasValidLine = collect($this->items)->contains(fn($i) => ($i['quantity'] ?? 0) > 0 && ($i['unit_price'] ?? 0) > 0);
            if (! $hasValidLine) {
                $validator->errors()->add('items', 'Au moins une ligne doit avoir une quantité et un prix supérieurs à 0.');
            }

            // Facture → TVA obligatoire
            if ($this->type === 'facture' && (float) ($this->tax_rate ?? 0) <= 0) {
                $validator->errors()->add('tax_rate', 'La TVA est obligatoire pour une facture (18%).');
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required'                => 'Ajoutez au moins une ligne de vente.',
            'items.*.product_type.required'  => 'Le type de produit est requis pour chaque ligne.',
            'items.*.quantity.min'           => 'La quantité doit être supérieure à 0.',
        ];
    }
}
