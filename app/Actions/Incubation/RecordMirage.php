<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordMirage
{
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            if ($incubation->status === 'clos') {
                throw new \DomainException("Impossible d'effectuer un mirage sur un cycle clôturé.");
            }

            $fertile = (int) $data['fertile_eggs'];
            $rate = $incubation->eggs_count > 0 ? ($fertile / $incubation->eggs_count) * 100 : 0;

            $incubation->update([
                'fertile_eggs'   => $fertile,
                'fertility_rate' => $rate,
                'status'         => 'mirage_fait',
            ]);

            return $incubation->fresh();
        });
    }
}
