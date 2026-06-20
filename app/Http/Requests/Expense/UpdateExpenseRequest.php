<?php

namespace App\Http\Requests\Expense;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('depenses.M');
    }

    public function rules(): array
    {
        return [
            'category'       => ['required', 'string', Rule::in(array_keys(Expense::CATEGORIES))],
            'label'          => 'required|string|max:255',
            'amount'         => 'required|numeric|min:1',
            'expense_date'   => 'required|date|before_or_equal:today',
            'payment_method' => ['required', 'string', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'batch_id'       => 'nullable|integer|exists:batches,id',
            'supplier_name'  => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:2000',
            'justificatif'   => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'category.in'                  => 'Catégorie de dépense invalide.',
            'amount.min'                   => 'Le montant doit être supérieur à zéro.',
            'expense_date.before_or_equal' => 'La date de dépense ne peut pas être dans le futur.',
            'payment_method.in'            => 'Mode de paiement invalide.',
            'justificatif.mimes'           => 'Le justificatif doit être un PDF ou une image (JPG, PNG).',
            'justificatif.max'             => 'Le justificatif ne doit pas dépasser 5 Mo.',
        ];
    }
}
