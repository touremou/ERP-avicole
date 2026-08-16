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
use Illuminate\Support\Facades\DB;
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
        $buildings = Building::physical()->orderBy('name')->get();
        $lastWater = WaterReading::whereIn('water_source_id', $sources->pluck('id'))
            ->get()->sortByDesc('reading_date')->groupBy('water_source_id')
            ->map(fn ($r) => $r->first()->only(['volume_consumed_liters', 'volume_added_liters', 'quality_ph', 'chlorine_level', 'cost', 'building_id']));

        // Historique des ravitaillements (appoints) par citerne : tout relevé qui
        // a ajouté de l'eau (volume_added > 0), le plus récent d'abord.
        $refills = WaterReading::whereIn('water_source_id', $sources->pluck('id'))
            ->where('is_refill', true)
            ->orderByDesc('reading_date')->orderByDesc('id')
            ->get()->groupBy('water_source_id');

        return view('utilities.water-sources', compact('sources', 'buildings', 'lastWater', 'refills'));
    }

    public function storeWaterSource(Request $request)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'type'             => 'required|in:seeg,forage,citerne,camion',
            'capacity_liters'  => 'nullable|numeric|min:0',
            'is_default'       => 'nullable|boolean',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $validated['is_default'] = $request->boolean('is_default');

        if ($validated['type'] === 'citerne' && ! empty($validated['capacity_liters'])) {
            $validated['current_level_liters'] = $validated['capacity_liters'];
            $validated['current_level_percent'] = 100;
        }

        // Une seule source « par défaut » par ferme : on retire le drapeau des autres.
        if ($validated['is_default']) {
            WaterSource::where('is_default', true)->update(['is_default' => false]);
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

        // Règles métier (coût estimé, unicité par jour, niveau citerne) : dans
        // l'action — SOURCE UNIQUE avec la sync mobile (M5).
        app(\App\Actions\Utility\RecordWaterReading::class)->execute($validated, Auth::id());

        return back()->with('success', "Relevé eau enregistré pour le {$validated['reading_date']}.");
    }

    public function refillWaterSource(Request $request, WaterSource $source)
    {
        if (Gate::denies('ressources.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'volume_added_liters' => 'required|numeric|min:1',
            'refill_date'         => 'required|date|before_or_equal:today',
            'cost'                => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:500',
        ]);

        /*
         * TOUT LE RAVITAILLEMENT SOUS VERROU.
         *
         * Ce qui suit est un lire-puis-écrire : on lit le niveau, on vérifie
         * qu'il reste de la place, puis on recalcule le niveau À PARTIR DE LA
         * MÊME VALEUR LUE. Deux appoints simultanés voyaient donc le même
         * niveau de départ, passaient tous deux le contrôle de capacité, et le
         * second écrasait le premier : deux relevés enregistrés, un seul
         * comptabilisé dans la citerne.
         *
         * La synchro mobile verrouillait déjà la source pour ce même geste
         * (`WaterSource::lockForUpdate()`), et porte le même garde-fou
         * anti-débordement. La règle était donc identique des deux côtés — seule
         * la sérialisation manquait au web.
         */
        return DB::transaction(function () use ($source, $validated) {
            $source = WaterSource::lockForUpdate()->findOrFail($source->id);

            // Une citerne ne peut pas être remplie au-delà de sa capacité : message
            // clair plutôt qu'un dépassement/erreur silencieuse.
            if ($source->type === 'citerne' && $source->capacity_liters) {
                $remaining = (float) $source->capacity_liters - (float) $source->current_level_liters;
                if ((float) $validated['volume_added_liters'] > $remaining + 0.01) {
                    return back()->with('error', 'Ravitaillement supérieur à la capacité : il reste '
                        . number_format(max(0, $remaining), 0, ',', ' ') . " L disponibles dans « {$source->name} ».");
                }
            }

            // Trace l'appoint comme un événement (consommation 0) — plusieurs
            // ravitaillements le même jour sont possibles (create, pas updateOrCreate).
            WaterReading::create([
                'water_source_id'        => $source->id,
                'reading_date'           => $validated['refill_date'],
                'user_id'                => Auth::id(),
                'volume_consumed_liters' => 0,
                'volume_added_liters'    => $validated['volume_added_liters'],
                'is_refill'              => true,
                'cost'                   => $validated['cost'] ?? 0,
                'notes'                  => $validated['notes'] ?? null,
            ]);

            // Niveau : on ajoute directement le volume ravitaillé (plafonné à la
            // capacité). Direct plutôt que refreshLevel() pour rester exact quel que
            // soit le nombre d'appoints/relevés du jour.
            if ($source->type === 'citerne' && $source->capacity_liters) {
                $newLevel = min((float) $source->capacity_liters,
                    (float) $source->current_level_liters + (float) $validated['volume_added_liters']);
                $source->update([
                    'current_level_liters'  => $newLevel,
                    'current_level_percent' => min(100, $newLevel / (float) $source->capacity_liters * 100),
                ]);
            }

                return back()->with('success', 'Ravitaillement de ' . number_format((float) $validated['volume_added_liters']) . " L enregistré pour « {$source->name} ».");
        });
    }

    // ──────────────────────────────────────────────
    // SOURCES D'ÉNERGIE
    // ──────────────────────────────────────────────

    public function energySources()
    {
        if (Gate::denies('ressources.L')) return back()->with('error', 'Accès restreint.');

        $sources = EnergySource::withCount('readings')->get();
        $buildings = Building::physical()->orderBy('name')->get();
        $lastEnergy = EnergyReading::whereIn('energy_source_id', $sources->pluck('id'))
            ->get()->sortByDesc('reading_date')->groupBy('energy_source_id')
            ->map(fn ($r) => $r->first()->only(['building_id']));

        return view('utilities.energy-sources', compact('sources', 'buildings', 'lastEnergy'));
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

        // Anti-corvée (carburant/coût estimés), compteurs, alerte gasoil et
        // bascule maintenance : dans l'action — SOURCE UNIQUE avec la sync (M5).
        $result = app(\App\Actions\Utility\RecordEnergyReading::class)->execute($validated, Auth::id());

        $suffix = $result['notes'] ? ' (' . implode(' · ', $result['notes']) . ')' : '';

        return back()->with('success', "Relevé énergie enregistré pour le {$validated['reading_date']}.{$suffix}");
    }

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

        // Achat = mouvement de cuve (opérationnel) + sortie de trésorerie : on
        // tient les deux de façon atomique, et l'achat poste sa dépense carburant.
        DB::transaction(function () use ($validated, $source, $newLevel) {
            $source->update(['current_fuel_level' => $newLevel]);

            $purchase = FuelPurchase::create($validated);
            $purchase->setRelation('source', $source);
            $purchase->syncLedgerExpense();
        });

        return back()->with('success',
            number_format($validated['quantity_liters']) . "L de carburant enregistrés (dépense générée). " .
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
            'is_default'       => 'nullable|boolean',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $validated['is_default'] = $request->boolean('is_default');

        // Une seule source « par défaut » par ferme.
        if ($validated['is_default']) {
            WaterSource::where('is_default', true)->where('id', '!=', $source->id)->update(['is_default' => false]);
        }

        $source->update($validated);

        // Anti-débordement : si la capacité passe sous le niveau actuel, on recale
        // le niveau (et le %) pour qu'une citerne ne dépasse jamais sa capacité.
        if (! empty($validated['capacity_liters']) && (float) $source->current_level_liters > (float) $validated['capacity_liters']) {
            $source->update([
                'current_level_liters'  => (float) $validated['capacity_liters'],
                'current_level_percent' => 100,
            ]);
        }

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

        DB::transaction(function () use ($purchase, $validated) {
            $purchase->update($validated);
            $purchase->syncLedgerExpense(); // répercute le nouveau montant sur la dépense liée
        });

        return redirect()->route('utilities.fuel.index')->with('success', 'Achat carburant mis à jour.');
    }

    public function destroyFuelPurchase(FuelPurchase $purchase)
    {
        if (Gate::denies('ressources.S')) return back()->with('error', 'Suppression réservée aux administrateurs.');

        DB::transaction(function () use ($purchase) {
            $purchase->expense?->delete(); // retire aussi l'écriture du registre des dépenses
            $purchase->delete();
        });

        return back()->with('success', 'Achat et dépense liée supprimés.');
    }
}
