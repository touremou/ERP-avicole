<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateEmployeeRequest extends FormRequest
{
    use EmployeeRules;

    public function authorize(): bool
    {
        return Gate::allows('M');
    }

    public function rules(): array
    {
        // L'employé vient de la route : on exclut son propre numéro de l'unicité.
        $employeeId = $this->route('employee')->id;

        return array_merge($this->commonEmployeeRules(), [
            'phone'  => 'required|string|max:20|unique:employees,phone,' . $employeeId,
            // Le statut RH n'existe qu'après l'embauche (un entrant est Actif).
            'status' => 'required|in:Actif,Suspendu,Congé',
        ]);
    }

    public function messages(): array
    {
        return $this->employeeMessages();
    }
}
