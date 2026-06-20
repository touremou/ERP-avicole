<?php

namespace App\Actions\Building;

use App\Models\Building;
use Illuminate\Support\Facades\DB;

class CreateBuilding
{
    public function execute(array $data): Building
    {
        return DB::transaction(function () use ($data) {
            // Un nouveau bâtiment industriel démarre toujours à l'état à vide
            $data['status'] = Building::STATUS_VIDE;

            return Building::create($data);
        });
    }
}