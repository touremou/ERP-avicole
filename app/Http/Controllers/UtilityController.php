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

        /*
         * LA CUVE ET LA DATE ÉTAIENT PROPOSÉES PAR L'ÉCRAN ET JETÉES PAR LA PORTE.
         *
         * `edit-fuel.blade.php` rend `energy_source_id` en select REQUIRED et
         * `purchase_date` en date REQUIRED. Ni l'un ni l'autre n'était validé
         * ici : `$request->validate()` ne les rendait pas, et `update()` ne
         * pouvait donc pas les écrire.
         *
         * Mesuré — un plein saisi sur le mauvais groupe et à la mauvaise date,
         * corrigé par l'écran : la réponse est « Achat carburant mis à jour. »
         * et RIEN ne change. Ni la cuve, ni la date, ni la dépense liée —
         * `syncLedgerExpense()` bâtit son libellé sur le nom de la source et sa
         * `expense_date` sur la date d'achat, donc le coût reste imputé au
         * mauvais groupe électrogène ET au mauvais mois du compte de résultat.
         *
         * L'écran de correction étant le seul recours, l'erreur était définitive.
         */
        $validated = $request->validate([
            'energy_source_id'  => 'required|exists:energy_sources,id',
            'purchase_date'     => 'required|date|before_or_equal:today',
            'quantity_liters'   => 'required|numeric|min:1',
            'unit_price'        => 'required|numeric|min:0',
            'supplier'          => 'nullable|string|max:255',
            'receipt_reference' => 'nullable|string|max:100',
            'notes'             => 'nullable|string|max:500',
        ]);

        $validated['total_cost'] = (float) $validated['quantity_liters'] * (float) $validated['unit_price'];

        // Ce que cet achat avait crédité, et à qui : il faut le rendre avant de
        // créditer autrement (cf. `regulariserLaCuve`).
        $ancienneSource = (int) $purchase->energy_source_id;
        $ancienVolume   = (float) $purchase->quantity_liters;

        DB::transaction(function () use ($purchase, $validated, $ancienneSource, $ancienVolume) {
            $purchase->update($validated);

            $this->regulariserLaCuve(
                $ancienneSource,
                $ancienVolume,
                (int) $validated['energy_source_id'],
                (float) $validated['quantity_liters'],
                $purchase
            );

            $purchase->syncLedgerExpense(); // répercute le nouveau montant sur la dépense liée
        });

        return redirect()->route('utilities.fuel.index')->with('success', 'Achat carburant mis à jour.');
    }

    /**
     * REND À L'ANCIENNE CUVE CE QUE L'ACHAT LUI AVAIT CRÉDITÉ, PUIS CRÉDITE LA
     * NOUVELLE DU NOUVEAU VOLUME.
     *
     * `current_fuel_level` n'est pas un calcul : c'est un SOLDE COURANT, crédité
     * par les achats et débité par les relevés de consommation
     * (`RecordEnergyReading`). Il porte l'autonomie affichée au tableau de bord
     * et l'alerte « commander du carburant ».
     *
     * La correction d'un achat ne le touchait PAS — défaut antérieur, mesuré :
     * un plein de 100 L corrigé à 150 L laissait la cuve à 100. Le stock
     * physique annoncé était faux de la différence, et l'alerte de niveau bas
     * avec lui.
     *
     * Ouvrir le changement de CUVE sans cette régularisation aurait aggravé les
     * choses : l'achat serait parti sur le Caterpillar en laissant ses litres
     * crédités au Perkins. Une seule règle couvre les deux — c'est le motif de
     * régularisation par delta que `UpdateFeedPurchase` applique déjà au stock
     * d'aliment.
     *
     * Le plafond de cuve est appliqué comme à la création : on ne déclare pas
     * plus que ce que la cuve peut contenir.
     */
    private function regulariserLaCuve(
        int $ancienneSourceId,
        float $ancienVolume,
        int $nouvelleSourceId,
        float $nouveauVolume,
        FuelPurchase $purchase
    ): void {
        if ($ancienneSourceId && $ancienne = EnergySource::find($ancienneSourceId)) {
            $ancienne->update([
                'current_fuel_level' => max(0, (float) $ancienne->current_fuel_level - $ancienVolume),
            ]);
        }

        $nouvelle = EnergySource::find($nouvelleSourceId);
        if (! $nouvelle) {
            return;
        }

        /*
         * L'ancienne et la nouvelle cuve peuvent être la MÊME — corriger un
         * volume sans changer de groupe est le cas courant. C'est l'ORDRE qui
         * le rend juste : le débit ci-dessus est écrit avant que `find()` ne
         * relise la cuve, donc le niveau lu porte déjà la restitution.
         */
        $niveau = (float) $nouvelle->current_fuel_level + $nouveauVolume;

        if ($nouvelle->fuel_tank_capacity && $niveau > (float) $nouvelle->fuel_tank_capacity) {
            $niveau = (float) $nouvelle->fuel_tank_capacity;
        }

        $nouvelle->update(['current_fuel_level' => $niveau]);
        $purchase->update(['fuel_level_after' => $niveau]);
    }

    public function destroyFuelPurchase(FuelPurchase $purchase)
    {
        if (Gate::denies('ressources.S')) return back()->with('error', 'Suppression réservée aux administrateurs.');

        /*
         * SUPPRIMER UN PLEIN LAISSAIT SES LITRES DANS LA CUVE.
         *
         * `storeFuelPurchase` CRÉDITE `current_fuel_level` du volume acheté.
         * La suppression retirait l'achat et sa dépense, et ne rendait rien :
         * la cuve continuait d'annoncer un carburant dont plus aucune pièce ne
         * justifie l'entrée.
         *
         * Mesuré : un plein de 100 L supprimé laisse la cuve à 100 L. Et ce
         * solde n'est pas décoratif — il porte l'autonomie du tableau de bord
         * et l'alerte « commander du carburant », qui ne partira donc pas.
         *
         * C'est le pendant exact de la régularisation posée à la MODIFICATION :
         * un geste qui défait un achat doit défaire ce que l'achat avait fait.
         */
        $cuveId  = (int) $purchase->energy_source_id;
        $litres  = (float) $purchase->quantity_liters;

        DB::transaction(function () use ($purchase, $cuveId, $litres) {
            /*
             * LA DÉPENSE S'ANNULE — ELLE NE S'EFFACE PAS.
             *
             * On appelait `$purchase->expense?->delete()`, c'est-à-dire le geste
             * que `ExpenseController::destroy` REFUSE, et pour une raison qu'il
             * écrit en toutes lettres : « Une dépense validée ne se supprime pas
             * — elle s'annule. […] L'annulation garde la pièce, sa trace et son
             * motif ; la suppression efface tout. » `ValidatedExpenseCannotBeDeletedTest`
             * fige ce refus.
             *
             * La garde vit sur le contrôleur des dépenses ; en appelant le
             * modèle directement, ce chemin-ci passait à côté — et la dépense
             * d'un achat de carburant est TOUJOURS validée, puisque
             * `syncLedgerExpense()` la crée ainsi. Le geste interdit par une
             * porte était donc accompli par une autre, sur chaque suppression.
             *
             * Après annulation, le registre garde la pièce : son libellé, son
             * montant, sa date, son fournisseur, sa référence de reçu. Le coût
             * quitte le compte de résultat — c'est le but — mais il reste
             * possible de répondre à « qu'est-ce qui a été annulé, et quand ».
             */
            $purchase->expense?->update(['status' => 'annule']);
            $purchase->delete();

            if ($cuveId && $cuve = EnergySource::find($cuveId)) {
                $cuve->update([
                    'current_fuel_level' => max(0, (float) $cuve->current_fuel_level - $litres),
                ]);
            }
        });

        return back()->with('success', 'Achat supprimé — la dépense liée est annulée et reste au registre.');
    }
}
