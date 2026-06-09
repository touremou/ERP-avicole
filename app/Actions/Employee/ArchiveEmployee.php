<?php

namespace App\Actions\Employee;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

class ArchiveEmployee
{
    public function execute(Employee $employee): void
    {
        // VÉRIFICATION CRITIQUE : Un responsable de lot actif ne peut pas être supprimé
        if ($employee->batches()->where('status', 'Actif')->exists()) {
            throw ValidationException::withMessages([
                'employee' => "🔒 ARCHIVAGE BLOQUÉ : Cet agent est actuellement responsable d'une bande en production."
            ]);
        }

        $employee->delete();
    }
}