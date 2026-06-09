<?php

namespace App\Actions\Employee;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class UpdateEmployee
{
    public function execute(Employee $employee, array $data, ?UploadedFile $photo, ?UploadedFile $cv): Employee
    {
        return DB::transaction(function () use ($employee, $data, $photo, $cv) {
            
            // Remplacement Photo sécurisé
            if ($photo) {
                if ($employee->photo_path && Storage::disk('public')->exists($employee->photo_path)) {
                    Storage::disk('public')->delete($employee->photo_path);
                }
                $data['photo_path'] = $photo->store('employees/photos', 'public');
            }

            // Remplacement CV sécurisé
            if ($cv) {
                if ($employee->cv_path && Storage::disk('public')->exists($employee->cv_path)) {
                    Storage::disk('public')->delete($employee->cv_path);
                }
                $data['cv_path'] = $cv->store('employees/cvs', 'public');
            }

            $employee->update($data);

            return $employee;
        });
    }
}