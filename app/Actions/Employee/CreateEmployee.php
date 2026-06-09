<?php

namespace App\Actions\Employee;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

class CreateEmployee
{
    public function execute(array $data, ?UploadedFile $photo, ?UploadedFile $cv): Employee
    {
        return DB::transaction(function () use ($data, $photo, $cv) {
            $data['status'] = 'Actif';

            if ($photo) {
                $data['photo_path'] = $photo->store('employees/photos', 'public');
            }
            if ($cv) {
                $data['cv_path'] = $cv->store('employees/cvs', 'public');
            }

            return Employee::create($data);
        });
    }
}