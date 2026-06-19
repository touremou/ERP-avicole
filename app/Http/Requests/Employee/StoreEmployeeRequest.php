<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        return [
            'last_name'     => 'required|string|max:255',
            'first_name'    => 'required|string|max:255',
            'phone'         => 'required|string|max:20|unique:employees,phone',
            'job_title'     => 'required|string|max:255',
            'department'    => 'required|string|max:255',
            'contract_type' => 'required|in:CDI,CDD,Journalier',
            'hire_date'     => 'required|date',
            'salary'        => 'nullable|numeric|min:0',
            'gender'        => 'required|in:M,F',
            'photo'         => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'cv'            => 'nullable|mimes:pdf|max:2048',
            'employee_id'   => 'nullable|string|max:255',
        ];
    }
}