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
            $previousPhoto = $employee->photo_path;

            if ($photo) {
                // Purge des DEUX emplacements : l'ancienne photo peut encore
                // être sur le disque servi en statique, et l'y laisser
                // reviendrait à garder publiquement lisible le visage qu'on
                // vient justement de remplacer.
                \App\Support\PrivateUpload::delete($employee->photo_path);
                $data['photo_path'] = \App\Support\PrivateUpload::store($photo, 'employees/photos');
            }

            // Remplacement CV sécurisé
            if ($cv) {
                \App\Support\PrivateUpload::delete($employee->cv_path);
                $data['cv_path'] = \App\Support\PrivateUpload::store($cv, 'employees/cvs');
            }

            $employee->update($data);

            // Le visage mis à jour sur la fiche descend sur le compte : sinon
            // l'agent garderait l'ancienne photo sur son téléphone, et le
            // responsable croirait l'avoir changée partout.
            if ($photo) {
                app(\App\Actions\Hr\SyncPersonPhoto::class)
                    ->fromEmployee($employee, $employee->photo_path, $previousPhoto);
            }

            return $employee;
        });
    }
}