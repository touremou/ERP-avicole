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
use Illuminate\Support\Str;

class ChickDispatchController extends Controller
{
    /**
     * Page de dispatch des poussins d'une incubation.
     */
    public function show(Incubation $incubation)
    {
        if (Gate::denies('production.L')) return back()->with('error', 'Accès restreint.');

        $incubation->load(['incubator', 'batch', 'chickDispatches.batch', 'chickDispatches.client']);

        $remaining = $this->getRemaining($incubation);
        $dispatches = $incubation->chickDispatches()->orderBy('created_at', 'desc')->get();

        // Données pour les formulaires
        $buildings = Building::whereIn('type', ['poussiniere', 'chair', 'mixte'])
            ->where('status', '!=', 'Maintenance')
            ->orderBy('name')
            ->get();
        $clients = Client::active()->orderBy('name')->get();
        $employees = Employee::where('status', 'actif')->orderBy('first_name')->get();

        return view('repro.dispatch', compact(
            'incubation', 'remaining', 'dispatches', 'buildings', 'clients', 'employees'
        ));
    }

    /**
     * Enregistrer un dispatch (destination + quantité).
     */
    public function store(Request $request, Incubation $incubation)
    {
        if (Gate::denies('production.C')) return back()->with('error', 'Action non autorisée.');

        $remaining = $this->getRemaining($incubation);

        $validated = $request->validate([
            'destination_type' => 'required|in:elevage,vente,stock,perte',
            'quantity'         => "required|integer|min:1|max:{$remaining}",
            'quality_grade'    => 'required|in:A,B,C',
            'notes'            => 'nullable|string|max:500',
            // Élevage
            'building_id'      => 'required_if:destination_type,elevage|nullable|exists:buildings,id',
            'employee_id'      => 'nullable|exists:employees,id',
            // Vente
            'client_id'        => 'required_if:destination_type,vente|nullable|exists:clients,id',
            'unit_price'       => 'required_if:destination_type,vente|nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($incubation, $validated) {
            $qty = (int) $validated['quantity'];
            $dispatchData = [
                'incubation_id'    => $incubation->id,
                'destination_type' => $validated['destination_type'],
                'quantity'         => $qty,
                'quality_grade'    => $validated['quality_grade'],
                'notes'            => $validated['notes'] ?? null,
                'dispatched_by'    => Auth::id(),
                'dispatch_date'    => now()->toDateString(),
            ];

            $message = '';

            // ═══════════════════════════════════════════
            // ÉLEVAGE → Créer un lot en poussinière
            // ═══════════════════════════════════════════
            if ($validated['destination_type'] === 'elevage') {
                $batch = Batch::create([
                    'uuid'                   => (string) Str::uuid(),
                    'code'                   => 'POUS-' . now()->format('Ymd-His'),
                    'type'                   => 'poussiniere',
                    'building_id'            => $validated['building_id'],
                    'employee_id'            => $validated['employee_id'] ?? null,
                    'provider_id'            => $incubation->provider_id ?? null,
                    'model_name'             => $incubation->batch?->model_name ?? null,
                    'initial_quantity'        => $qty,
                    'current_quantity'        => $qty,
                    'qty_alive'              => $qty,
                    'qty_dead'               => 0,
                    'arrival_date'           => now()->toDateString(),
                    'expected_end_date'      => now()->addDays(90),
                    'buy_price_per_unit'     => 0,
                    'total_acquisition_cost'  => 0,
                    'status'                 => 'Actif',
                    'chick_state'            => 'Normal',
                    'observations'           => "Poussins issus du couvoir — Incubation {$incubation->code_incubation}",
                    'is_synced'              => true,
                ]);

                $dispatchData['batch_id'] = $batch->id;
                $message = "{$qty} poussins démarrés en poussinière → Lot {$batch->code}";
            }

            // ═══════════════════════════════════════════
            // VENTE → Enregistrer la vente client
            // ═══════════════════════════════════════════
            elseif ($validated['destination_type'] === 'vente') {
                $unitPrice = (float) ($validated['unit_price'] ?? 0);
                $total = $qty * $unitPrice;

                $dispatchData['client_id'] = $validated['client_id'];
                $dispatchData['unit_price'] = $unitPrice;
                $dispatchData['total_amount'] = $total;

                $clientName = Client::find($validated['client_id'])?->name ?? '—';
                $message = "{$qty} poussins vendus à {$clientName} pour " . number_format($total, 0, ',', '.') . " GNF";
            }

            // ═══════════════════════════════════════════
            // STOCK → Ajouter au stock "Poussins d'un jour"
            // ═══════════════════════════════════════════
            elseif ($validated['destination_type'] === 'stock') {
                $stock = Stock::firstOrCreate(
                    ['item_name' => 'Poussins d\'un jour', 'category' => 'produits_finis'],
                    ['unit' => 'TETE', 'current_quantity' => 0, 'alert_threshold' => 0]
                );

                $stock->increment('current_quantity', $qty);

                StockMovement::create([
                    'stock_id'     => $stock->id,
                    'type'         => 'in',
                    'quantity'     => $qty,
                    'unit'         => 'TETE',
                    'reason'       => "Éclosion {$incubation->code_incubation} — {$qty} poussins",
                    'performed_by' => Auth::id(),
                ]);

                $message = "{$qty} poussins ajoutés au stock (Poussins d'un jour)";
            }

            // ═══════════════════════════════════════════
            // PERTE → Traçabilité uniquement
            // ═══════════════════════════════════════════
            else {
                $message = "{$qty} poussins comptabilisés en perte — " . ($validated['notes'] ?? 'sans détail');
            }

            // Enregistrer le dispatch
            ChickDispatch::create($dispatchData);

            // Mettre à jour le compteur sur l'incubation
            $totalDispatched = ChickDispatch::where('incubation_id', $incubation->id)->sum('quantity');
            $incubation->update([
                'chicks_dispatched' => $totalDispatched,
                'chicks_remaining'  => max(0, ($incubation->hatched_chicks ?? 0) - $totalDispatched),
            ]);

            return back()->with('success', $message);
        });
    }

    /**
     * Calcule les poussins restants à dispatcher.
     */
    private function getRemaining(Incubation $incubation): int
    {
        $hatched = (int) ($incubation->hatched_chicks ?? 0);
        $dispatched = (int) ChickDispatch::where('incubation_id', $incubation->id)->sum('quantity');
        return max(0, $hatched - $dispatched);
    }
}
