<?php

namespace App\Actions\Protocol;

use App\Models\Protocol;
use Illuminate\Support\Facades\DB;

class DuplicateProtocol
{
    /**
     * Clone un protocole et l'intégralité de sa séquence.
     */
    public function execute(Protocol $protocol): Protocol
    {
        return DB::transaction(function () use ($protocol) {
            $clone = $protocol->replicate();
            $clone->name = $protocol->name . ' (COPIE)';
            $clone->save();

            foreach ($protocol->steps as $step) {
                $stepClone = $step->replicate();
                $stepClone->protocol_id = $clone->id;
                $stepClone->save();
            }

            return $clone;
        });
    }
}