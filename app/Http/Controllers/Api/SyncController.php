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
use Illuminate\Support\Facades\Gate;
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
            'gate'    => 'elevage.L',
            'columns' => ['id', 'uuid', 'code', 'status', 'building_id', 'species_id', 'production_type_id',
                          'employee_id', 'initial_quantity', 'current_quantity', 'qty_dead', 'arrival_date', 'updated_at'],
            // Attribut dérivé (calculé serveur) : le terrain ne peut pas
            // reproduire la règle d'éligibilité ponte (normes de souche, âge).
            'append'  => ['can_collect_eggs'],
        ],
        'buildings' => [
            'model'   => Building::class,
            'gate'    => 'elevage.L',
            'columns' => ['id', 'name', 'type', 'capacity', 'status', 'updated_at'],
        ],
        'stocks' => [
            'model'   => Stock::class,
            'gate'    => 'logistique.L',
            'columns' => ['id', 'item_name', 'category', 'unit', 'current_quantity', 'alert_threshold', 'updated_at'],
        ],
        'clients' => [
            'model'   => Client::class,
            'gate'    => 'commerce.L',
            'columns' => ['id', 'client_id', 'name', 'category', 'phone', 'balance', 'status', 'updated_at'],
        ],
        // Citernes / sources d'eau : pour le ravitaillement terrain hors-ligne.
        // Gate any-of : qui peut ravitailler (C) doit recevoir les citernes,
        // même sans lecture explicite (L).
        'water_sources' => [
            'model'   => \App\Models\WaterSource::class,
            'gate'    => ['ressources.L', 'ressources.C'],
            'columns' => ['id', 'name', 'type', 'capacity_liters', 'current_level_liters',
                          'current_level_percent', 'is_active', 'updated_at'],
        ],
        'products' => [
            'model'   => Product::class,
            'gate'    => 'commerce.L',
            'columns' => ['id', 'name', 'sku', 'product_type', 'unit', 'base_price', 'is_active', 'updated_at'],
        ],
        // Référentiel global (non borné ferme) : permet au client de savoir
        // quel lot est en ponte (tâche « collecte d'œufs ») sans dupliquer la
        // taxonomie. Table petite et stable.
        'production_types' => [
            'model'   => \App\Models\ProductionType::class,
            'columns' => ['id', 'slug', 'name_fr', 'updated_at'],
        ],
        // ── Phase 3 : cultures, abattoir, provenderie ──
        'plots' => [
            'model'   => \App\Models\Plot::class,
            'gate'    => 'cultures.L',
            'columns' => ['id', 'code', 'name', 'status', 'area_ha', 'updated_at'],
        ],
        'crop_cycles' => [
            'model'   => \App\Models\CropCycle::class,
            'gate'    => 'cultures.L',
            'columns' => ['id', 'uuid', 'plot_id', 'code', 'crop_name', 'variety', 'status',
                          'employee_id', 'planting_date', 'updated_at'],
        ],
        'slaughter_orders' => [
            'model'   => \App\Models\SlaughterOrder::class,
            'gate'    => 'abattoir.L',
            'columns' => ['id', 'order_number', 'batch_id', 'planned_date', 'planned_quantity',
                          'status', 'requested_by', 'executed_by', 'updated_at'],
        ],
        'formulas' => [
            'model'   => \App\Models\Formula::class,
            'gate'    => 'provenderie.L',
            'columns' => ['id', 'name', 'code', 'target_type', 'is_active', 'updated_at'],
        ],
        // Éleveurs livreurs pour la réception du vif (CCP 1) — pas de
        // données financières ni de coordonnées complètes. Référentiel
        // partagé : accessible à qui lit un module qui l'utilise (arrivée de
        // lot, réception abattoir, achats aliment, commerce).
        'providers' => [
            'model'   => \App\Models\Provider::class,
            'gate'    => ['annuaire.L', 'elevage.L', 'abattoir.L', 'provenderie.L', 'commerce.L'],
            'columns' => ['id', 'name', 'type', 'status', 'updated_at'],
        ],
        // Pas de SoftDeletes sur mill_productions/formulas : jamais de
        // tombstones — un OP annulé reste visible avec son statut « Annulé ».
        'mill_productions' => [
            'model'   => \App\Models\MillProduction::class,
            'gate'    => 'provenderie.L',
            'columns' => ['id', 'batch_number', 'formula_id', 'quantity_produced', 'status',
                          'operator_id', 'supervisor_id', 'started_at', 'updated_at'],
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

                $result = ['status' => 'error', 'message' => __('Erreur interne lors de la réconciliation.')];
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
            // Cloisonnement RBAC : on ne descend au terrain QUE les référentiels
            // des modules que l'utilisateur a le droit de lire (L). Un vendeur ne
            // reçoit ainsi ni les lots (élevage) ni les formules (provenderie)…
            // 'gate' peut être un slug ou une liste (accès si l'un au moins passe).
            if (isset($config['gate'])) {
                $gates = (array) $config['gate'];
                $allowed = false;
                foreach ($gates as $gate) {
                    if (Gate::allows($gate)) { $allowed = true; break; }
                }
                if (! $allowed) {
                    $entities[$key] = ['upserts' => [], 'deletes' => []];
                    continue;
                }
            }

            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = $config['model'];

            // Upserts : enregistrements (de la ferme courante — FarmScope actif)
            // créés/modifiés depuis `since`. Bootstrap complet si since absent.
            $query = $model::query()
                ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
                ->orderBy('id');

            if (! empty($config['append'])) {
                // Attribut(s) dérivé(s) : on hydrate le modèle complet (la règle
                // peut dépendre de colonnes hors liste blanche), puis on ne
                // sérialise que la liste blanche + les attributs calculés.
                $upserts = $query->get()->map(function ($record) use ($config) {
                    $row = [];
                    foreach ($config['columns'] as $column) {
                        $row[$column] = $record->getAttribute($column);
                    }
                    foreach ($config['append'] as $attribute) {
                        // camelCase → méthode (can_collect_eggs → canCollectEggs).
                        $method = \Illuminate\Support\Str::camel($attribute);
                        $row[$attribute] = method_exists($record, $method)
                            ? $record->{$method}()
                            : $record->getAttribute($attribute);
                    }

                    return $row;
                });
            } else {
                $upserts = $query->get($config['columns']);
            }

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
