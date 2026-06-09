<?php

namespace App\Actions\Protocol;

use App\Models\Protocol;
use Illuminate\Support\Facades\DB;

class CreateProtocol
{
    /**
     * Crée un nouveau protocole et ses étapes chronologiques.
     */
    public function execute(array $data): Protocol
    {
        return DB::transaction(function () use ($data) {
            $protocol = Protocol::create([
                'name'        => $data['name'],
                'type'        => $data['type'],
                'strain'      => $data['strain'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

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

            return $protocol;
        });
    }
}