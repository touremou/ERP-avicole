<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

class ToggleProviderBlacklist
{
    public function execute(Provider $provider, bool $isBlacklisting): Provider
    {
        return DB::transaction(function () use ($provider, $isBlacklisting) {
            $provider->update([
                'status'      => $isBlacklisting ? 'Blacklisté' : 'Actif',
                'reliability' => $isBlacklisting ? 'Mauvais' : 'Bon'
            ]);

            return $provider;
        });
    }
}