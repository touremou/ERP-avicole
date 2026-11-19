<?php

namespace App\Http\Requests\Sale;

use App\Models\Client;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('commerce.C');
    }

    public function rules(): array
    {
        // 👈 Récupération dynamique du taux de TVA
        $tvaRate = setting('general.tva_rate', 18);

        return [
            'client_id'              => ['required', \App\Support\Ferme::existe('clients')],
            'sale_date'              => 'required|date|before_or_equal:today',
            'type'                   => 'required|in:bon_livraison,facture',
            // 👈 Injection du taux dynamique
            'tax_rate'               => 'nullable|numeric|in:0,' . $tvaRate, 
            'delivery_mode'          => 'nullable|in:sur_place,livraison',
            'delivery_address'       => 'nullable|string|max:500',
            'delivery_notes'         => 'nullable|string|max:500',
            'delivery_fee'           => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:2000',

            // Lignes de vente — taxonomie multiespèces.
            // animal_vif / carcasse / lait sont génériques ; volaille_vivante
            // et volaille_abattue restent acceptés (rétrocompatibilité).
            'items'                  => 'required|array|min:1',
            'discount_type'          => 'nullable|in:none,percent,amount',
            'discount_value'         => 'nullable|numeric|min:0',
            // Les référentiels partagés avec la vente du terrain (SyncService::saleCreate).
            'items.*.product_type'   => ['required', \Illuminate\Validation\Rule::in(\App\Models\SaleItem::typesAdmis())],
            'items.*.product_name'   => 'required|string|max:255',
            'items.*.product_id'     => 'nullable|integer',
            'items.*.product_ref_id' => 'nullable|integer|exists:products,id',
            'items.*.batch_id'       => ['nullable', 'integer', \App\Support\Ferme::existe('batches')],
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => ['required', \Illuminate\Validation\Rule::in(\App\Models\SaleItem::UNITES)],
            'items.*.unit_price'     => 'required|numeric|min:0',

            // Paiement immédiat (optionnel)
            'immediate_payment'      => 'nullable|numeric|min:0',
            'payment_method'         => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Payment::METHODES)],
            'payment_reference'      => 'nullable|string|max:100',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Statut du client et plafond crédit : règle unique portée par le
            // modèle (Client::creditRefusalReason), pour que le formulaire du
            // bureau, la synchro terrain et la validation ne puissent pas
            // diverger — c'était le cas, et seul cet écran-ci l'appliquait.
            $client = Client::find($this->client_id);
            if ($client) {
                // Le total que la vente PORTERA (TVA, remise, livraison, arrondi —
                // Sale::totalProjete), le même que la validation contrôlera.
                $newCredit = Sale::totalProjete($this->all()) - (float) ($this->immediate_payment ?? 0);

                if ($raison = $client->creditRefusalReason((float) $newCredit)) {
                    $validator->errors()->add('client_id', $raison);
                }
            }

            // Ligne à montant > 0, unité cohérente avec le type, TVA d'une
            // facture : règles déclarées sur le modèle (Sale::erreursDeSaisie),
            // lues aussi par la synchronisation — elles n'existaient qu'ici.
            foreach (Sale::erreursDeSaisie($this->all()) as $champ => $message) {
                $validator->errors()->add($champ, $message);
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required'                => 'Ajoutez au moins une ligne de vente.',
            'items.*.product_type.required' => 'Le type de produit est requis pour chaque ligne.',
            'items.*.quantity.min'          => 'La quantité doit être supérieure à 0.',
        ];
    }
}