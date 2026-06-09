<?php

namespace App\Actions\Protocol;

use App\Models\Protocol;
use Illuminate\Support\Facades\DB;

class UpdateProtocol
{
    /**
     * Met à jour le master protocole par écrasement strict des étapes.
     */
    public function execute(Protocol $protocol, array $data): Protocol
    {
        return DB::transaction(function () use ($protocol, $data) {
            $protocol->update([
                'name'        => $data['name'],
                'type'        => $data['type'],
                'strain'      => $data['strain'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            // Synchronisation stricte : on efface et on recrée
            $protocol->steps()->delete();

            if (!empty($data['steps'])) {
                foreach ($data['steps'] as $step) {
                    $protocol->steps()->create([
                        'day_number'  => $step['day_number'],
                        'action_name' => $step['action_name'],
                        'type'        => $step['type'],
                        'method'      => $step['method'] ?? 'Eau de boisson',
                    ]);
                }
            }

            return $protocol->fresh('steps');
        });
    }
}