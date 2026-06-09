<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

class UpdateProvider
{
    public function execute(Provider $provider, array $data): Provider
    {
        return DB::transaction(function () use ($provider, $data) {
            $provider->update($data);
            return $provider->fresh();
        });
    }
}