<?php
namespace App\Services;

use App\Models\Batch;
use Carbon\Carbon;

class SyncService
{
    public function reconcileBatch(array $clientData)
    {
        $serverBatch = Batch::where('uuid', $clientData['uuid'])->first();

        if ($serverBatch) {
            $clientUpdatedAt = Carbon::parse($clientData['updated_at']);
            
            // Si le serveur a été modifié APRES la saisie client, on garde le serveur
            if ($serverBatch->updated_at->gt($clientUpdatedAt)) {
                return [
                    'status' => 'conflict',
                    'message' => 'Donnée serveur plus récente, mise à jour ignorée.',
                    'data' => $serverBatch
                ];
            }
        }

        // Sinon, on procède à la mise à jour (Logique updateOrCreate standard)
        return app(BatchService::class)->updateOrCreateBatch($clientData);
    }
}