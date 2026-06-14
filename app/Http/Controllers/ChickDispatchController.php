<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Building;
use App\Models\ChickDispatch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Incubation;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ChickDispatchController extends Controller
{
    public function show(Incubation $incubation)
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $incubation->load(['incubator', 'batch', 'chickDispatches.batch', 'chickDispatches.client']);

        $remaining = $this->getRemaining($incubation);
        $dispatches = $incubation->chickDispatches()->orderBy('created_at', 'desc')->get();

        $buildings = Building::whereIn('type', ['poussiniere', 'chair', 'mixte'])
            ->orderBy('name')->get();
        $clients = Client::orderBy('name')->get();
        $employees = Employee::where('status', 'actif')->orderBy('first_name')->get();

        return view('incubations.dispatch', compact(
            'incubation', 'remaining', 'dispatches', 'buildings', 'clients', 'employees'
        ));
    }

    public function store(Request $request, Incubation $incubation)
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');

        $remaining = $this->getRemaining($incubation);

        if ($remaining <= 0) {
            return back()->with('error', 'Tous les poussins ont déjà été dispatchés.');
        }

        // ═══ VALIDATION (array syntax pour éviter le conflit required_if|pipe) ═══
        $validated = $request->validate([
            'destination_type' => ['required', 'in:elevage,vente,stock,perte'],
            'quantity'         => ['required', 'integer', 'min:1', "max:{$remaining}"],
            'quality_grade'    => ['required', 'in:A,B,C'],
            'notes'            => ['nullable', 'string', 'max:500'],
            // Élevage uniquement
            'building_id'      => ['nullable', 'required_if:destination_type,elevage', 'exists:buildings,id'],
            'employee_id'      => ['nullable', 'exists:employees,id'],
            // Vente uniquement
            'client_id'        => ['nullable', 'required_if:destination_type,vente', 'exists:clients,id'],
            'unit_price'       => ['nullable', 'required_if:destination_type,vente', 'numeric', 'min:0'],
        ]);

        // ═══ CONTRÔLE BÂTIMENT (type + capacité) pour la mise en élevage ═══
        // Un lot ne doit pas atterrir dans un bâtiment de vocation incompatible
        // (ex. zone de ponte/repro) ni au-delà de la capacité disponible.
        if ($validated['destination_type'] === 'elevage') {
            $building = Building::findOrFail($validated['building_id']);

            $allowedTypes = ['poussiniere', 'chair', 'mixte'];
            if (! in_array($building->type, $allowedTypes, true)) {
                return back()->withErrors([
                    'building_id' => "Le bâtiment {$building->name} (type « {$building->type} ») ne peut pas accueillir de poussins. Types autorisés : poussinière, chair, mixte.",
                ])->withInput();
            }

            $occupied  = (int) Batch::where('building_id', $building->id)->active()->sum('current_quantity');
            $available = (int) $building->capacity - $occupied;
            if ((int) $validated['quantity'] > $available) {
                return back()->withErrors([
                    'building_id' => "Capacité insuffisante dans {$building->name} : {$validated['quantity']} poussins demandés, {$available} places disponibles (capacité {$building->capacity}, occupé {$occupied}).",
                ])->withInput();
            }
        }

        return DB::transaction(function () use ($incubation, $validated) {
            $qty = (int) $validated['quantity'];
            $dest = $validated['destination_type'];

            $dispatchData = [
                'incubation_id'    => $incubation->id,
                'destination_type' => $dest,
                'quantity'         => $qty,
                'quality_grade'    => $validated['quality_grade'],
                'notes'            => $validated['notes'] ?? null,
                'dispatched_by'    => Auth::id(),
                'dispatch_date'    => now()->toDateString(),
            ];

            $message = '';

            // ═══ ÉLEVAGE → Créer lot poussinière ═══
            if ($dest === 'elevage') {
                // Héritage depuis le lot source de l'incubation (traçabilité) :
                // espèce et fournisseur d'origine. Le type de production devient
                // « poussiniere » de l'espèce héritée. provider_id peut être null
                // (éclosion à la ferme, sans achat).
                $sourceBatch = $incubation->batch;
                $speciesId   = $sourceBatch?->species_id;
                $productionTypeId = \App\Models\ProductionType::resolveOrCreate('poussiniere', $speciesId)->id;

                $batch = Batch::create([
                    'uuid'                   => (string) Str::uuid(),
                    'code'                   => setting('elevage.batch_prefix_poussiniere', 'POUS') . '-' . now()->format('Ymd-His'),
                    'species_id'             => $speciesId,
                    'production_type_id'     => $productionTypeId,
                    'building_id'            => $validated['building_id'],
                    'employee_id'            => $validated['employee_id'] ?? null,
                    'provider_id'            => $sourceBatch?->provider_id,
                    'model_name'             => $sourceBatch?->model_name ?? 'Non spécifié',
                    'initial_quantity'        => $qty,
                    'current_quantity'        => $qty,
                    'qty_dead'               => 0,
                    'qty_males'              => 0,
                    'qty_females'            => 0,
                    'arrival_date'           => now()->toDateString(),
                    'expected_end_date'      => now()->addDays(90),
                    'avg_weight_start'       => 0,
                    'planned_density'        => 0,
                    'arrival_mortality_rate' => 0,
                    'buy_price_per_unit'     => 0,
                    'total_acquisition_cost'  => 0,
                    'status'                 => 'Actif',
                    'chick_state'            => 'Normal',
                    'observations'           => "Couvoir — {$incubation->code_incubation}",
                    'is_synced'              => true,
                ]);

                // Le bâtiment accueille désormais un lot actif.
                Building::where('id', $validated['building_id'])->update(['status' => Building::STATUS_OCCUPE]);

                $dispatchData['batch_id'] = $batch->id;
                $message = "{$qty} poussins démarrés en poussinière → Lot {$batch->code}";
            }

            // ═══ VENTE → Enregistrer vente client ═══
            elseif ($dest === 'vente') {
                $unitPrice = (float) ($validated['unit_price'] ?? 0);
                $total = $qty * $unitPrice;

                $dispatchData['client_id'] = $validated['client_id'];
                $dispatchData['unit_price'] = $unitPrice;
                $dispatchData['total_amount'] = $total;

                $clientName = Client::find($validated['client_id'])?->name ?? '—';
                $message = "{$qty} poussins vendus à {$clientName} — " . number_format($total, 0, ',', '.') . " GNF";
            }

            // ═══ STOCK → Ajouter au stock "Poussins d'un jour" ═══
            elseif ($dest === 'stock') {
                try {
                    // Construire les critères avec farm_id si applicable
                    $criteria = ['item_name' => 'Poussins d\'un jour', 'category' => 'produits_finis'];
                    $defaults = ['unit' => 'TETE', 'current_quantity' => 0, 'alert_threshold' => 0];

                    $farmId = session('current_farm_id');
                    if ($farmId && Schema::hasColumn('stocks', 'farm_id')) {
                        $criteria['farm_id'] = $farmId;
                        $defaults['farm_id'] = $farmId;
                    }

                    $stock = Stock::withoutGlobalScopes()->firstOrCreate($criteria, $defaults);
                    $stock->increment('current_quantity', $qty);

                    // Mouvement de stock
                    $movData = [
                        'stock_id'     => $stock->id,
                        'type'         => 'in',
                        'quantity'     => $qty,
                        'unit'         => 'TETE',
                        'user_id'      => Auth::id(),
                        'notes'        => "Éclosion {$incubation->code_incubation} — {$qty} poussins",
                    ];
                    if ($farmId && Schema::hasColumn('stock_movements', 'farm_id')) {
                        $movData['farm_id'] = $farmId;
                    }
                    StockMovement::create($movData);

                    $message = "{$qty} poussins ajoutés au stock (Poussins d'un jour)";
                } catch (\Throwable $e) {
                    Log::error("Dispatch stock failed: {$e->getMessage()}");
                    throw new \RuntimeException("Erreur lors de la mise en stock : {$e->getMessage()}");
                }
            }

            // ═══ PERTE → Traçabilité uniquement ═══
            elseif ($dest === 'perte') {
                $message = "{$qty} poussins comptabilisés en perte — " . ($validated['notes'] ?? 'sans détail');
                Log::warning("Perte poussins: {$qty} — Incubation {$incubation->code_incubation} — " . ($validated['notes'] ?? 'N/A'));
            }

            // Enregistrer le dispatch
            ChickDispatch::create($dispatchData);

            // Mettre à jour les compteurs
            $this->refreshCounters($incubation);

            return back()->with('success', $message);
        });
    }

    /**
     * Recalcule les compteurs de dispatch sur l'incubation.
     */
    private function refreshCounters(Incubation $incubation): void
    {
        $totalDispatched = (int) ChickDispatch::where('incubation_id', $incubation->id)->sum('quantity');
        $hatched = (int) ($incubation->hatched_chicks ?? 0);

        $updateData = [];
        if (Schema::hasColumn('incubations', 'chicks_dispatched')) {
            $updateData['chicks_dispatched'] = $totalDispatched;
        }
        if (Schema::hasColumn('incubations', 'chicks_remaining')) {
            $updateData['chicks_remaining'] = max(0, $hatched - $totalDispatched);
        }

        if (! empty($updateData)) {
            $incubation->update($updateData);
        }
    }

    /**
     * Calcule les poussins restants (source de vérité = base de données).
     */
    private function getRemaining(Incubation $incubation): int
    {
        $hatched = (int) ($incubation->hatched_chicks ?? 0);
        $dispatched = (int) ChickDispatch::where('incubation_id', $incubation->id)->sum('quantity');
        return max(0, $hatched - $dispatched);
    }
}
