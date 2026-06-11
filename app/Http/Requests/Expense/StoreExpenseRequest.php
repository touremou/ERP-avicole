<?php

namespace App\Http\Requests\Expense;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('depenses.C');
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
        ];
    }

    public function messages(): array
    {
        return [
            'category.required'         => 'La catégorie de dépense est obligatoire.',
            'category.in'               => 'Catégorie de dépense invalide.',
            'label.required'            => 'Le libellé de la dépense est obligatoire.',
            'amount.required'           => 'Le montant est obligatoire.',
            'amount.min'                => 'Le montant doit être supérieur à zéro.',
            'expense_date.before_or_equal' => 'La date de dépense ne peut pas être dans le futur.',
            'payment_method.in'         => 'Mode de paiement invalide.',
        ];
    }
}
