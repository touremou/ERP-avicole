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

            // Dotation initiale de congés annuels pilotée par les paramètres (RH).
            if (! isset($data['annual_leave_balance'])) {
                $data['annual_leave_balance'] = (int) setting('rh.annual_leave_days', 30);
            }

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