<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Client;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Sync\SyncService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * API v1 — Synchronisation offline (porte d'entrée UNIQUE).
 *
 * push : reçoit la file d'outbox du terrain (opérations idempotentes à uuid)
 *        et renvoie un statut PAR opération — une opération invalide ne fait
 *        jamais échouer le lot (cf. docs/mobile/phase-0-spec.md §4.2).
 * pull : delta des données de référence depuis `since` (upserts + tombstones),
 *        borné à la ferme courante par FarmScope (middleware farm.api).
 */
class SyncController extends Controller
{
    /**
     * Colonnes exposées au terrain par entité (liste blanche stricte —
     * on ne sérialise JAMAIS un modèle entier vers l'extérieur).
     *
     * @var array<string, array{model: class-string, columns: array<int, string>}>
     */
    private const PULL_ENTITIES = [
        'batches' => [
            'model'   => Batch::class,
            'columns' => ['id', 'uuid', 'code', 'status', 'building_id', 'species_id', 'production_type_id',
                          'initial_quantity', 'current_quantity', 'qty_dead', 'arrival_date', 'updated_at'],
        ],
        'buildings' => [
            'model'   => Building::class,
            'columns' => ['id', 'name', 'type', 'capacity', 'status', 'updated_at'],
        ],
        'stocks' => [
            'model'   => Stock::class,
            'columns' => ['id', 'item_name', 'category', 'unit', 'current_quantity', 'updated_at'],
        ],
        'clients' => [
            'model'   => Client::class,
            'columns' => ['id', 'client_id', 'name', 'category', 'phone', 'balance', 'status', 'updated_at'],
        ],
        'products' => [
            'model'   => Product::class,
            'columns' => ['id', 'name', 'sku', 'product_type', 'unit', 'base_price', 'is_active', 'updated_at'],
        ],
        // Référentiel global (non borné ferme) : permet au client de savoir
        // quel lot est en ponte (tâche « collecte d'œufs ») sans dupliquer la
        // taxonomie. Table petite et stable.
        'production_types' => [
            'model'   => \App\Models\ProductionType::class,
            'columns' => ['id', 'slug', 'name_fr', 'updated_at'],
        ],
    ];

    public function push(Request $request, SyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'operations'            => 'required|array|min:1|max:100',
            'operations.*.op_uuid'  => 'required|uuid',
            'operations.*.type'     => 'required|string|max:50',
            'operations.*.payload'  => 'required|array',
        ]);

        $results = [];

        foreach ($validated['operations'] as $operation) {
            try {
                $result = $sync->handle($operation['type'], $operation['payload']);
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Règle métier violée au plus profond de l'Action (ex. stock
                // aliment insuffisant, capacité bâtiment) : refus NON REJOUABLE.
                // Sans ce catch dédié, l'op tomberait en 'error' générique et le
                // terrain la retenterait indéfiniment — ici elle sort de la file
                // vers le bac « À corriger » avec le motif exact.
                $result = [
                    'status'  => 'conflict',
                    'message' => $e->getMessage(),
                    'errors'  => $e->errors(),
                ];
            } catch (\Throwable $e) {
                // Une opération ne doit JAMAIS faire échouer le lot : on logue
                // et on renvoie un statut d'erreur ciblé au client (retenté).
                Log::error("Sync push: échec op {$operation['op_uuid']} ({$operation['type']}) : {$e->getMessage()}", [
                    'exception' => $e,
                ]);

                $result = ['status' => 'error', 'message' => 'Erreur interne lors de la réconciliation.'];
            }

            $results[] = array_merge(['op_uuid' => $operation['op_uuid']], $result);
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'results'     => $results,
        ]);
    }

    public function pull(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => 'nullable|date',
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;

        $entities = [];

        foreach (self::PULL_ENTITIES as $key => $config) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = $config['model'];

            // Upserts : enregistrements (de la ferme courante — FarmScope actif)
            // créés/modifiés depuis `since`. Bootstrap complet si since absent.
            $upserts = $model::query()
                ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
                ->orderBy('id')
                ->get($config['columns']);

            // Tombstones : ids soft-supprimés depuis `since`, pour purger le
            // miroir local. Entités sans SoftDeletes → liste vide.
            $deletes = [];
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $deletes = $model::onlyTrashed()
                    ->when($since, fn ($q) => $q->where('deleted_at', '>', $since))
                    ->orderBy('id')
                    ->pluck('id');
            }

            $entities[$key] = [
                'upserts' => $upserts,
                'deletes' => $deletes,
            ];
        }

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'entities'    => $entities,
        ]);
    }
}
