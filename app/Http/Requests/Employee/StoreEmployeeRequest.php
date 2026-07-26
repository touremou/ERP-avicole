<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreEmployeeRequest extends FormRequest
{
    use EmployeeRules;

    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        return array_merge($this->commonEmployeeRules(), [
            // Unicité : propre à l'embauche (à la mise à jour, on s'exclut).
            'phone'       => 'required|string|max:20|unique:employees,phone',
            'employee_id' => 'nullable|string|max:255',
        ]);
    }

    public function messages(): array
    {
        return $this->employeeMessages();
    }
}
