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
     * Clés : model, columns (liste blanche), gate (slug ou liste any-of),
     * scope (périmètre sur le modèle), append (attributs dérivés), with
     * (relations préchargées pour ces attributs), full (envoi INTÉGRAL du
     * périmètre, `since` ignoré — pour une liste de travail dont on sort sans
     * tombstone).
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
            // price_list_id (M6) : le POS mobile re-tarife tout l'écran au
            // changement de client, hors réseau — l'AJAX web ne peut pas.
            //
            // credit_limit : depuis #237, le serveur OPPOSE le plafond aux
            // ventes venues du terrain — mais le terrain ne le recevait pas. Il
            // ne pouvait donc pas voir la règle qu'on lui applique : la vente
            // partait, la marchandise aussi, et le refus n'arrivait qu'à la
            // synchronisation suivante, définitif et sans recours. Le solde
            // descendait déjà ; il manquait la borne à laquelle le comparer.
            'columns' => ['id', 'client_id', 'name', 'category', 'price_list_id', 'phone',
                          'balance', 'credit_limit', 'status', 'updated_at'],
        ],
        // M5 — Couvoir : cycles d'incubation OUVERTS (mirage/éclosion se font
        // en salle). Les cycles clos ne servent plus au terrain.
        'incubations' => [
            'model'   => \App\Models\Incubation::class,
            'gate'    => 'production.L',
            'columns' => ['id', 'uuid', 'code_incubation', 'batch_id', 'incubator_id', 'start_date',
                          'hatch_date_expected', 'eggs_count', 'fertile_eggs', 'hatched_chicks',
                          'status', 'updated_at'],
            'scope'   => 'openForSync',
        ],
        // M5 — Sources d'énergie : relevé du compteur groupe lu sur place.
        'energy_sources' => [
            'model'   => \App\Models\EnergySource::class,
            'gate'    => 'ressources.L',
            'columns' => ['id', 'name', 'type', 'fuel_type', 'current_fuel_level', 'status', 'updated_at'],
            'scope'   => 'activeForSync',
        ],
        // M4 — Machines du moulin : lancer un OP au terrain exige de choisir
        // la (les) machine(s) et de connaître leur état.
        'mill_machines' => [
            'model'   => \App\Models\MillMachine::class,
            'gate'    => 'provenderie.L',
            'columns' => ['id', 'name', 'type', 'capacity_per_hour', 'status', 'updated_at'],
        ],
        // M4/M6 — Employés actifs : superviseur d'un OP, et grille de présence.
        // Colonnes strictement nominatives : ni salaire, ni contrat, ni contact
        // d'urgence ne descendent sur un téléphone de terrain.
        // Gate any-of : qui pointe la présence (rh.C) doit recevoir la liste,
        // même sans lecture RH explicite (rh.L).
        'employees' => [
            'model'   => \App\Models\Employee::class,
            'gate'    => ['provenderie.L', 'elevage.L', 'rh.L', 'rh.C'],
            'columns' => ['id', 'employee_id', 'first_name', 'last_name', 'job_title', 'status', 'updated_at'],
            'scope'   => 'activeForSync',
        ],
        // M2 — CRÉANCES OUVERTES : ventes non soldées des 90 derniers jours, pour
        // que le livreur puisse encaisser et traiter un retour chez le client
        // sans réseau. Le reste dû est recalculé serveur au push (le montant
        // local n'est qu'un guide de saisie).
        'sales' => [
            'model'   => \App\Models\Sale::class,
            'gate'    => 'commerce.L',
            'columns' => ['id', 'uuid', 'reference', 'client_id', 'sale_date', 'status',
                          'total_amount', 'paid_amount', 'payment_status', 'updated_at'],
            'scope'   => 'openReceivablesForSync',
        ],
        'sale_items' => [
            'model'   => \App\Models\SaleItem::class,
            'gate'    => 'commerce.L',
            'columns' => ['id', 'sale_id', 'product_name', 'product_type', 'quantity', 'unit',
                          'unit_price', 'total', 'updated_at'],
            'scope'   => 'forOpenReceivablesSync',
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
            'columns' => ['id', 'name', 'sku', 'product_type', 'unit', 'base_price',
                          'stock_id', 'is_favorite', 'is_active', 'updated_at'],
            // available_quantity (M6) : le stock vendable, pour clamper le
            // panier au terrain. Descendu ICI et pas via `stocks` — un vendeur a
            // commerce.L sans forcément logistique.L, il ne recevrait rien.
            // null = article non suivi en stock (vendable librement).
            'append'  => ['available_quantity'],
            'with'    => ['stock'],
        ],
        // M6 — Tarifs par palier client (détail / demi-gros / grossiste). Le web
        // les résout par AJAX (sales.catalog-prices) ; au terrain il faut la
        // table pour appliquer la même cascade hors réseau.
        'sale_price_lists' => [
            'model'   => \App\Models\SalePriceList::class,
            'gate'    => 'commerce.L',
            'columns' => ['id', 'name', 'is_default', 'updated_at'],
        ],
        'sale_price_list_items' => [
            'model'   => \App\Models\SalePriceListItem::class,
            'gate'    => 'commerce.L',
            'columns' => ['id', 'sale_price_list_id', 'product_id', 'product_type', 'unit_price', 'updated_at'],
            'scope'   => 'forFarmSync',
        ],
        // M6 — Congés validés courants : le pointage mobile ne doit pas déclarer
        // présent un employé en congé (parité avec le pré-remplissage web).
        // Aucun motif ne descend : ni type, ni raison, ni validateur.
        'employee_leaves' => [
            'model'   => \App\Models\EmployeeLeave::class,
            'gate'    => ['rh.L', 'rh.C'],
            'columns' => ['id', 'employee_id', 'start_date', 'end_date', 'status', 'updated_at'],
            'scope'   => 'currentForSync',
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
                          'employee_id', 'planting_date', 'area_used_ha', 'updated_at'],
        ],
        'slaughter_orders' => [
            'model'   => \App\Models\SlaughterOrder::class,
            'gate'    => 'abattoir.L',
            'columns' => ['id', 'order_number', 'batch_id', 'planned_date', 'planned_quantity',
                          'status', 'closed_at', 'requested_by', 'executed_by', 'updated_at'],
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
        // T2 — Lots en conservation OUVERTS : le contrôle périodique se fait au
        // magasin, balance en main. On descend aussi l'objectif de prix et
        // l'échéance : le contrôleur doit savoir ce qu'il cherche à décider.
        'stored_lots' => [
            'model'   => \App\Models\StoredLot::class,
            'gate'    => ['logistique.L', 'logistique.C'],
            'columns' => ['id', 'uuid', 'stock_id', 'label', 'quantity_initial', 'quantity_current',
                          'unit', 'unit_cost', 'target_unit_price', 'last_market_price',
                          'opened_at', 'hold_until', 'check_interval_days', 'status', 'updated_at'],
            'scope'   => 'open',
            // Un lot vendu ou détruit quitte le périmètre SANS tombstone (il
            // change juste de statut) : sans envoi intégral, il resterait dans la
            // liste de contrôle du terrain, qui pèserait un lot qui n'existe plus.
            'full'    => true,
        ],
        // T1 — Recettes de transformation végétale : le séchoir a besoin du
        // rendement de référence pour que l'opérateur voie tout de suite si sa
        // sortie est plausible (même jauge que la découpe d'abattoir).
        'crop_recipes' => [
            'model'   => \App\Models\CropRecipe::class,
            'gate'    => 'cultures.L',
            'columns' => ['id', 'code', 'name', 'transformation_type', 'output_product',
                          'output_unit', 'expected_yield_percent', 'shelf_life_days',
                          'is_active', 'updated_at'],
            'scope'   => 'active',
        ],
        // T1 — Récoltes EN ATTENTE DE TRANSFORMATION : la liste de travail de
        // l'atelier. Choisir la récolte donne la traçabilité au lot ET le coût
        // matière ; sans elle, le terrain devrait ressaisir les deux.
        'pending_harvests' => [
            'model'   => \App\Models\Harvest::class,
            'gate'    => 'cultures.L',
            'columns' => ['id', 'uuid', 'crop_cycle_id', 'harvest_date', 'quantity', 'unit',
                          'net_weight_kg', 'quality', 'destination', 'stock_item_name', 'updated_at'],
            'scope'   => 'awaitingTransformationForSync',
            // ENVOI INTÉGRAL : une récolte transformée quitte ce périmètre SANS
            // tombstone (elle n'est pas supprimée, juste plus « en attente »).
            // Un delta la laisserait dans la liste de travail de l'atelier et
            // l'opérateur engagerait deux fois la même matière. Le client
            // remplace donc la liste entière à chaque pull — d'où l'envoi
            // complet, borné par la fenêtre de 60 jours du scope.
            'full'    => true,
        ],
        // Catalogue agronomique des cultures (espèces) — référentiel GLOBAL
        // (non cloisonné par ferme), pour proposer la liste des cultures au
        // pointage de semis mobile (parité avec le datalist du formulaire web).
        'crop_species' => [
            'model'   => \App\Models\CropSpecies::class,
            'gate'    => 'cultures.L',
            // Matériel de plantation : le mobile adapte le même libellé que le
            // web (« Nombre de rejets » pour un ananas). Sans ces colonnes,
            // l'écran de semis resterait bloqué sur « semence en kg » — une
            // divergence entre les deux supports pour la même culture.
            'columns' => ['id', 'name', 'local_name', 'type',
                'planting_material', 'planting_unit', 'planting_density',
                // Poids moyen de l'unité récoltée : au champ, le technicien COMPTE
                // des fruits. L'application peut alors proposer le poids net, que
                // T1 exige pour valoriser une récolte conservée — sinon le champ
                // reste vide et la matière n'a pas de valeur.
                'avg_unit_weight_kg', 'harvest_unit_label', 'updated_at'],
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
            // `full` : listes de travail dont on sort sans tombstone — on renvoie
            // tout le périmètre courant, le client remplace intégralement.
            $useDelta = $since !== null && empty($config['full']);

            $query = $model::query()
                ->when($useDelta, fn ($q) => $q->where('updated_at', '>', $since))
                ->orderBy('id');

            // Périmètre optionnel : certains référentiels seraient trop lourds
            // (ou hors sujet) descendus en entier — ex. seules les ventes NON
            // SOLDÉES récentes intéressent l'encaissement terrain. Le scope vit
            // sur le modèle, jamais dans le contrôleur.
            if (! empty($config['scope'])) {
                $query->{$config['scope']}();
            }

            // Relation(s) préchargée(s) : un attribut dérivé qui traverse une
            // relation (ex. products.available_quantity → stock) ferait sinon
            // une requête PAR enregistrement.
            if (! empty($config['with'])) {
                $query->with($config['with']);
            }

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
