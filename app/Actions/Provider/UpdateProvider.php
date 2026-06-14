<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpdateProvider
{
    public function execute(Provider $provider, array $data, ?UploadedFile $logo = null): Provider
    {
        return DB::transaction(function () use ($provider, $data, $logo) {
            if ($logo) {
                // Supprime l'ancien logo avant d'enregistrer le nouveau.
                if ($provider->logo_path && Storage::disk('public')->exists($provider->logo_path)) {
                    Storage::disk('public')->delete($provider->logo_path);
                }
                $data['logo_path'] = $logo->store('providers/logos', 'public');
            }

            $provider->update($data);
            return $provider->fresh();
        });
    }
}
