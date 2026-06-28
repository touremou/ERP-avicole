<?php

namespace App\Http\Requests\Sale;

use App\Models\Client;
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
            'client_id'              => 'required|exists:clients,id',
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
            'items.*.product_type'   => 'required|in:oeufs,animal_vif,carcasse,lait,fumier,litieres,aliment,produits_finis,materiel,autre,volaille_vivante,volaille_abattue',
            'items.*.product_name'   => 'required|string|max:255',
            'items.*.product_id'     => 'nullable|integer',
            'items.*.product_ref_id' => 'nullable|integer|exists:products,id',
            'items.*.batch_id'       => 'nullable|integer|exists:batches,id',
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.unit'           => 'required|in:alveole,unite,kg,piece,sac,voyage,tete,litre',
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

            // Cohérence unité ↔ type de produit (défense côté serveur si le JS
            // est contourné : éviter ex. du lait enregistré en kg).
            $allowedUnits = [
                'oeufs'             => ['alveole', 'unite'],
                'animal_vif'        => ['tete', 'piece', 'kg'],
                'volaille_vivante'  => ['tete', 'piece', 'kg'],
                'carcasse'          => ['kg'],
                'volaille_abattue'  => ['kg'],
                'lait'              => ['litre'],
                'aliment'           => ['kg', 'sac'],
                'fumier'            => ['sac', 'voyage'],
                'litieres'          => ['sac', 'unite', 'kg'],
                'produits_finis'    => ['kg', 'tete', 'piece', 'unite'],
                'materiel'          => ['unite', 'piece'],
                // 'autre' : libre, pas de contrainte
            ];
            foreach ((array) $this->items as $idx => $item) {
                // Ligne issue du CATALOGUE : l'article définit lui-même son unité
                // (validée à sa création). On ne lui applique pas la contrainte
                // unité↔type, qui ne vise que les lignes en saisie libre.
                if (! empty($item['product_ref_id'])) {
                    continue;
                }
                $type = $item['product_type'] ?? null;
                $unit = $item['unit'] ?? null;
                if ($type && isset($allowedUnits[$type]) && $unit && ! in_array($unit, $allowedUnits[$type], true)) {
                    $validator->errors()->add(
                        "items.{$idx}.unit",
                        "Unité « {$unit} » incohérente avec le type « {$type} »."
                    );
                }
            }

            // Facture → TVA obligatoire
            if ($this->type === 'facture' && (float) ($this->tax_rate ?? 0) <= 0) {
                // 👈 Affichage dynamique du taux dans le message d'erreur
                $tvaRate = setting('general.tva_rate', 18);
                $validator->errors()->add('tax_rate', "La TVA est obligatoire pour une facture ({$tvaRate}%).");
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