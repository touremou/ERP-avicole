<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

class CreateProvider
{
    public function execute(array $data): Provider
    {
        return DB::transaction(function () use ($data) {
            // Initialisation des valeurs par défaut de rigueur
            $data['status'] = 'Actif';
            
            // Si la fiabilité n'est pas spécifiée dans le formulaire
            if (empty($data['reliability'])) {
                $data['reliability'] = 'Bon';
            }

            return Provider::create($data);
        });
    }
}