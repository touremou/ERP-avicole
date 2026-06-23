<?php

namespace App\Http\Controllers;

use App\Models\AssetMaintenanceLog;
use App\Models\Building;
use App\Models\TaskAssignment;
use App\Models\WaterSource;
use App\Models\WaterReading;
use App\Models\EnergySource;
use App\Models\EnergyReading;
use App\Models\FuelPurchase;
use App\Services\NotificationHub;
use App\Services\UtilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class UtilityController extends Controller
{
    // ──────────────────────────────────────────────
    // DASHBOARD EAU & ÉNERGIE
    // ──────────────────────────────────────────────

    public function dashboard(Request $request, UtilityService $service)
    {
        if (Gate::denies('ressources.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $period = $request->input('period', 30);
        $data = $service->getDashboardData((int) $period);

        $waterSources = WaterSource::active()->get();
        $energySources = EnergySource::active()->get();
        $buildings = Building::physical()->orderBy('name')->get();

        // Saisie « comme hier » : dernier relevé par source pour pré-remplir le
        // formulaire à la sélection (réduit la friction de saisie quotidienne).
        $lastWater = WaterReading::whereIn('water_source_id', $waterSources->pluck('id'))
            ->get()->sortByDesc('reading_date')->groupBy('water_source_id')
            ->map(fn ($r) => $r->first()->only(['volume_consumed_liters', 'volume_added_liters', 'quality_ph', 'chlorine_level', 'cost', 'building_id']));

        // Énergie : on ne pré-remplit QUE le bâtiment desservi (attribution stable).
        // Heures/carburant/coût restent vides → le système estime carburant et
        // coût à partir des heures saisies (cf. storeEnergyReading), supprimant
        // la double saisie quotidienne.
        $lastEnergy = EnergyReading::whereIn('energy_source_id', $energySources->pluck('id'))
            ->get()->sortByDesc('reading_date')->groupBy('energy_source_id')
            ->map(fn ($r) => $r->first()->only(['building_id']));

        return view('utilities.dashboard', compact('data', 'waterSources', 'energySources', 'buildings', 'period', 'lastWater', 'lastEnergy'));
    }

    // ──────────────────────────────────────────────
    // SOURCES D'EAU
    // ──────────────────────────────────────────────

    public function waterSources()
    {
        if (Gate::denies('ressources.L')) return back()->with('error', 'Accès restreint.');

        $sources = WaterSource::withCount('readings')->get();
        return view('utilities.water-sources', compact('sources'));
    }

    public function storeWaterSource(Request $request)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'required|in:seeg,forage,citerne,camion',
            'capacity_liters'  => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
        ]);

        if ($validated['type'] === 'citerne' && ! empty($validated['capacity_liters'])) {
            $validated['current_level_liters'] = $validated['capacity_liters'];
            $validated['current_level_percent'] = 100;
        }

        WaterSource::create($validated);

        return back()->with('success', "Source d'eau \"{$validated['name']}\" enregistrée.");
    }

    // ──────────────────────────────────────────────
    // RELEVÉS D'EAU
    // ──────────────────────────────────────────────

    public function storeWaterReading(Request $request)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'water_source_id'        => 'required|exists:water_sources,id',
            'building_id'            => 'nullable|exists:buildings,id',
            'reading_date'           => 'required|date|before_or_equal:today',
            'volume_consumed_liters' => 'required|numeric|min:0',
            'volume_added_liters'    => 'nullable|numeric|min:0',
            'quality_ph'             => 'nullable|numeric|min:0|max:14',
            'chlorine_level'         => 'nullable|numeric|min:0|max:10',
            'cost'                   => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:500',
        ]);

        $validated['user_id'] = Auth::id();
        
        // CORRECTION : Forcer à 0 si la valeur est null
        $validated['volume_added_liters'] = $validated['volume_added_liters'] ?? 0;

        // Coût estimé depuis le prix du m³ (paramètre énergie) si non saisi.
        if (empty($validated['cost'])) {
            $pricePerM3 = (float) setting('energie.water_price_m3', 0);
            $validated['cost'] = round(($validated['volume_consumed_liters'] / 1000) * $pricePerM3, 2);
        }

        WaterReading::updateOrCreate(
            ['water_source_id' => $validated['water_source_id'], 'reading_date' => $validated['reading_date']],
            $validated
        );

        // Mettre à jour le niveau de la citerne
        $source = WaterSource::find($validated['water_source_id']);
        $source->refreshLevel();

        return back()->with('success', "Relevé eau enregistré pour le {$validated['reading_date']}.");
    }

    // ──────────────────────────────────────────────
    // SOURCES D'ÉNERGIE
    // ──────────────────────────────────────────────

    public function energySources()
    {
        if (Gate::denies('ressources.L')) return back()->with('error', 'Accès restreint.');

        $sources = EnergySource::withCount('readings')->get();
        return view('utilities.energy-sources', compact('sources'));
    }

    public function storeEnergySource(Request $request)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'                       => 'required|string|max:255',
            'type'                       => 'required|in:edg,groupe,solaire',
            'brand'                      => 'nullable|string|max:100',
            'model'                      => 'nullable|string|max:100',
            'serial_number'              => 'nullable|string|max:100',
            'capacity_kva'               => 'nullable|numeric|min:0',
            'fuel_type'                  => 'nullable|in:gasoil,essence',
            'fuel_tank_capacity'         => 'nullable|numeric|min:0',
            'maintenance_interval_hours' => 'nullable|integer|min:50',
            'notes'                      => 'nullable|string|max:1000',
            'purchase_date'              => 'nullable|date',
            'purchase_price'             => 'nullable|numeric|min:0',
            'depreciation_years'         => 'nullable|integer|min:1|max:30',
            'warranty_expiry'            => 'nullable|date',
            'service_contract_ref'       => 'nullable|string|max:255',
        ]);

        EnergySource::create($validated);

        return back()->with('success', "Source d'énergie \"{$validated['name']}\" enregistrée.");
    }

    public function recordMaintenance(Request $request, EnergySource $source)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'maintenance_type'  => 'required|in:vidange,filtres,inspection,reparation,contrat',
            'description'       => 'nullable|string|max:1000',
            'cost'              => 'nullable|numeric|min:0',
            'technician'        => 'nullable|string|max:255',
            'next_interval_hours' => 'nullable|integer|min:50',
        ]);

        $intervalHours = $validated['next_interval_hours'] ?? $source->maintenance_interval_hours;

        $source->update([
            'last_maintenance_at'        => now(),
            'next_maintenance_at'        => now()->addHours($intervalHours),
            'maintenance_interval_hours' => $intervalHours,
            'status'                     => 'operationnel',
        ]);

        // Journal CMMS
        $log = AssetMaintenanceLog::create([
            'farm_id'            => $source->farm_id,
            'energy_source_id'   => $source->id,
            'user_id'            => Auth::id(),
            'maintenance_date'   => now()->toDateString(),
            'type'               => $validated['maintenance_type'],
            'description'        => $validated['description'] ?? null,
            'cost'               => $validated['cost'] ?? null,
            'technician'         => $validated['technician'] ?? null,
            'hours_at_maintenance' => $source->total_hours_run,
        ]);

        // Compléter la tâche de maintenance préventive si elle existe aujourd'hui
        $task = TaskAssignment::withoutGlobalScopes()
            ->where('farm_id', $source->farm_id)
            ->where('category', 'maintenance_preventive')
            ->whereDate('scheduled_date', now()->toDateString())
            ->whereIn('status', ['a_faire', 'en_retard'])
            ->where('title', 'like', "%{$source->name}%")
            ->first();

        if ($task) {
            $task->update([
                'status'           => 'fait',
                'completed_at'     => now(),
                'completed_by'     => Auth::id(),
                'completion_notes' => "Maintenance effectuée — {$validated['maintenance_type']}.",
            ]);
            $log->update(['task_assignment_id' => $task->id]);
        }

        return back()->with('success', "Maintenance enregistrée pour {$source->name}. Prochaine révision dans {$intervalHours}h.");
    }

    public function assetLogs(EnergySource $source)
    {
        if (Gate::denies('ressources.L')) return back()->with('error', 'Accès restreint.');

        $sources = EnergySource::withCount('readings')->get();
        $logs = $source->maintenanceLogs()->with('user')->latest('maintenance_date')->get();

        return view('utilities.energy-sources', compact('sources', 'logs') + ['assetSource' => $source]);
    }

    // ──────────────────────────────────────────────
    // RELEVÉS ÉNERGIE
    // ──────────────────────────────────────────────

    public function storeEnergyReading(Request $request)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'energy_source_id'    => 'required|exists:energy_sources,id',
            'building_id'         => 'nullable|exists:buildings,id',
            'reading_date'        => 'required|date|before_or_equal:today',
            'hours_run'           => 'required|numeric|min:0|max:24',
            'fuel_consumed_liters' => 'nullable|numeric|min:0',
            'kwh_produced'        => 'nullable|numeric|min:0',
            'cost'                => 'nullable|numeric|min:0',
            'outage_hours'        => 'nullable|numeric|min:0|max:24',
            'notes'               => 'nullable|string|max:500',
        ]);

        $validated['user_id'] = Auth::id();
        $validated['outage_hours'] = $validated['outage_hours'] ?? 0;

        $source = EnergySource::find($validated['energy_source_id']);

        // ─── Anti-corvée : dériver carburant et coût quand ils ne sont pas saisis ───
        // L'opérateur ne renseigne idéalement que les heures ; le système estime
        // le carburant (heures × conso horaire moyenne) puis le coût (carburant ×
        // prix au litre). Toute valeur saisie manuellement est respectée.
        $autoNotes = [];

        if (empty($validated['fuel_consumed_liters'])
            && $source->type === 'groupe'
            && (float) $validated['hours_run'] > 0) {
            $litersPerHour = $source->averageLitersPerHour();
            if ($litersPerHour) {
                $validated['fuel_consumed_liters'] = round((float) $validated['hours_run'] * $litersPerHour, 1);
                $autoNotes[] = "carburant estimé " . number_format($validated['fuel_consumed_liters'], 1, ',', ' ') . " L";
            }
        }

        if (empty($validated['cost']) && ! empty($validated['fuel_consumed_liters'])) {
            // Prix réel le plus récent (dernier achat), repli sur le paramètre.
            $unitPrice = FuelPurchase::where('energy_source_id', $source->id)
                ->latest('purchase_date')->value('unit_price')
                ?? (float) setting('energie.fuel_price_liter', 12000);
            $validated['cost'] = round((float) $validated['fuel_consumed_liters'] * (float) $unitPrice);
            $autoNotes[] = "coût estimé " . number_format($validated['cost'], 0, ',', ' ') . " GNF";
        }

        EnergyReading::updateOrCreate(
            ['energy_source_id' => $validated['energy_source_id'], 'reading_date' => $validated['reading_date']],
            $validated
        );

        // Mettre à jour les heures totales et le niveau de carburant
        $source->increment('total_hours_run', (float) $validated['hours_run']);

        $wasFuelLow = $source->is_fuel_low;

        if (! empty($validated['fuel_consumed_liters']) && $source->current_fuel_level !== null) {
            $source->decrement('current_fuel_level', (float) $validated['fuel_consumed_liters']);
            if ($source->current_fuel_level < 0) {
                $source->update(['current_fuel_level' => 0]);
            }
        }

        // Alerte gasoil critique : seulement au moment où l'on franchit le seuil.
        if (! $wasFuelLow && $source->refresh()->is_fuel_low) {
            app(NotificationHub::class)->alertFuelLow($source);
        }

        // Vérifier si maintenance nécessaire
        if ($source->needs_maintenance && $source->status === 'operationnel') {
            $source->update(['status' => 'maintenance']);
        }

        $suffix = $autoNotes ? ' (' . implode(' · ', $autoNotes) . ')' : '';

        return back()->with('success', "Relevé énergie enregistré pour le {$validated['reading_date']}.{$suffix}");
    }

    // ──────────────────────────────────────────────
    // ACHATS CARBURANT
    // ──────────────────────────────────────────────

    public function fuelPurchases(Request $request)
    {
        if (Gate::denies('ressources.L')) return back()->with('error', 'Accès restreint.');

        $purchases = FuelPurchase::with(['source', 'user'])
            ->latest('purchase_date')
            ->paginate(20);

        $groupes = EnergySource::groupes()->get();

        return view('utilities.fuel-purchases', compact('purchases', 'groupes'));
    }

    public function storeFuelPurchase(Request $request)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'energy_source_id'  => 'required|exists:energy_sources,id',
            'building_id'       => 'nullable|exists:buildings,id',
            'purchase_date'     => 'required|date|before_or_equal:today',
            'quantity_liters'   => 'required|numeric|min:1',
            'unit_price'        => 'required|numeric|min:0',
            'supplier'          => 'nullable|string|max:255',
            'receipt_reference' => 'nullable|string|max:100',
            'notes'             => 'nullable|string|max:500',
        ]);

        $validated['user_id'] = Auth::id();
        $validated['total_cost'] = (float) $validated['quantity_liters'] * (float) $validated['unit_price'];

        // Mettre à jour le niveau de cuve
        $source = EnergySource::find($validated['energy_source_id']);
        $newLevel = ($source->current_fuel_level ?? 0) + (float) $validated['quantity_liters'];

        if ($source->fuel_tank_capacity && $newLevel > $source->fuel_tank_capacity) {
            $newLevel = $source->fuel_tank_capacity;
        }

        $validated['fuel_level_after'] = $newLevel;
        $source->update(['current_fuel_level' => $newLevel]);

        FuelPurchase::create($validated);

        return back()->with('success',
            number_format($validated['quantity_liters']) . "L de gasoil enregistrés. " .
            "Cuve {$source->name} : {$newLevel}L."
        );
    }

    // ──────────────────────────────────────────────
    // ÉDITION / SUPPRESSION
    // ──────────────────────────────────────────────

    public function editWaterSource(WaterSource $source)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');
        return view('utilities.water-sources', ['sources' => WaterSource::withCount('readings')->get(), 'editing' => $source]);
    }

    public function updateWaterSource(Request $request, WaterSource $source)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'required|in:seeg,forage,citerne,camion',
            'capacity_liters'  => 'nullable|numeric|min:0',
            'quality_status'   => 'nullable|in:bon,acceptable,traitement_requis',
            'is_active'        => 'boolean',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $source->update($validated);
        return redirect()->route('utilities.water.sources')->with('success', "Source \"{$source->name}\" mise à jour.");
    }

    public function destroyWaterSource(WaterSource $source)
    {
        if (Gate::denies('ressources.S')) return back()->with('error', 'Suppression réservée aux administrateurs.');
        $source->delete();
        return back()->with('success', "Source \"{$source->name}\" supprimée.");
    }

    public function editEnergySource(EnergySource $source)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');
        return view('utilities.edit-energy', ['source' => $source]);
    }

    public function updateEnergySource(Request $request, EnergySource $source)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'                       => 'required|string|max:255',
            'type'                       => 'required|in:edg,groupe,solaire',
            'brand'                      => 'nullable|string|max:100',
            'model'                      => 'nullable|string|max:100',
            'serial_number'              => 'nullable|string|max:100',
            'capacity_kva'               => 'nullable|numeric|min:0',
            'fuel_type'                  => 'nullable|in:gasoil,essence',
            'fuel_tank_capacity'         => 'nullable|numeric|min:0',
            'maintenance_interval_hours' => 'nullable|integer|min:50',
            'status'                     => 'nullable|in:operationnel,maintenance,panne',
            'is_active'                  => 'boolean',
            'notes'                      => 'nullable|string|max:1000',
            'purchase_date'              => 'nullable|date',
            'purchase_price'             => 'nullable|numeric|min:0',
            'depreciation_years'         => 'nullable|integer|min:1|max:30',
            'warranty_expiry'            => 'nullable|date',
            'service_contract_ref'       => 'nullable|string|max:255',
        ]);

        $source->update($validated);
        return redirect()->route('utilities.energy.sources')->with('success', "Source \"{$source->name}\" mise à jour.");
    }

    public function destroyEnergySource(EnergySource $source)
    {
        if (Gate::denies('ressources.S')) return back()->with('error', 'Suppression réservée aux administrateurs.');
        $source->delete();
        return back()->with('success', "Source \"{$source->name}\" supprimée.");
    }

    public function editFuelPurchase(FuelPurchase $purchase)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');
        $purchases = FuelPurchase::with(['source', 'user'])->latest('purchase_date')->paginate(20);
        $groupes = EnergySource::groupes()->get();
        return view('utilities.fuel-purchases', compact('purchases', 'groupes') + ['editing' => $purchase]);
    }

    public function updateFuelPurchase(Request $request, FuelPurchase $purchase)
    {
        if (Gate::denies('ressources.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'quantity_liters'   => 'required|numeric|min:1',
            'unit_price'        => 'required|numeric|min:0',
            'supplier'          => 'nullable|string|max:255',
            'receipt_reference' => 'nullable|string|max:100',
            'notes'             => 'nullable|string|max:500',
        ]);

        $validated['total_cost'] = (float) $validated['quantity_liters'] * (float) $validated['unit_price'];
        $purchase->update($validated);

        return redirect()->route('utilities.fuel.index')->with('success', 'Achat carburant mis à jour.');
    }

    public function destroyFuelPurchase(FuelPurchase $purchase)
    {
        if (Gate::denies('ressources.S')) return back()->with('error', 'Suppression réservée aux administrateurs.');
        $purchase->delete();
        return back()->with('success', 'Achat supprimé.');
    }
}
