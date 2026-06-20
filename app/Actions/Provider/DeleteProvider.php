<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Exception;

class DeleteProvider
{
    public function execute(Provider $provider): void
    {
        // Sécurité critique : blocage si lié à une production en cours
        if ($provider->batches()->active()->exists()) {
            throw new Exception("Ce partenaire est lié à une production en cours.");
        }

        DB::transaction(function () use ($provider) {
            $provider->delete(); // Déclenche le Soft Delete
        });
    }
}