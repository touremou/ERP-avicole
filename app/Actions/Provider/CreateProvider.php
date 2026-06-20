<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateProvider
{
    public function execute(array $data, ?UploadedFile $logo = null): Provider
    {
        return DB::transaction(function () use ($data, $logo) {
            // Initialisation des valeurs par défaut de rigueur
            $data['status'] = 'Actif';

            // Si la fiabilité n'est pas spécifiée dans le formulaire
            if (empty($data['reliability'])) {
                $data['reliability'] = 'Bon';
            }

            if ($logo) {
                $data['logo_path'] = $logo->store('providers/logos', 'public');
            }

            return Provider::create($data);
        });
    }
}
