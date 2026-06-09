<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('M');
    }

    public function rules(): array
    {
        // On récupère l'employé depuis la route pour exclure son propre numéro de la règle 'unique'
        $employeeId = $this->route('employee')->id;

        return [
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'phone'         => 'required|string|max:20|unique:employees,phone,' . $employeeId,
            'job_title'     => 'required|string|max:255',
            'department'    => 'required|string|max:255',
            'contract_type' => 'required|in:CDI,CDD,Journalier',
            'status'        => 'required|in:Actif,Suspendu,Congé',
            'salary'        => 'nullable|numeric|min:0',
            'photo'         => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'cv'            => 'nullable|mimes:pdf|max:5120',
        ];
    }
}