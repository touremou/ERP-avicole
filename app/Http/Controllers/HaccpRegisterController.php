<?php

namespace App\Http\Controllers;

use App\Actions\Slaughter\RecordCcp;
use App\Actions\Slaughter\RecordTemperatureLog;
use App\Models\CcpRecord;
use App\Models\CleaningLog;
use App\Models\SlaughterOrder;
use App\Models\TemperatureLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Registres HACCP de l'abattoir — CCP 1-4, températures (E4),
 * nettoyage/désinfection (E7). Tous INSERT-ONLY (RG-06) : la conformité
 * est calculée SERVEUR (Actions), jamais confiée au formulaire.
 * Export PDF opposable : releve_at ET synced_at figurent partout.
 */
class HaccpRegisterController extends Controller
{
    // ──────────────────────────────────────────────
    // REGISTRE CCP
    // ──────────────────────────────────────────────

    public function ccpIndex(Request $request)
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $records = CcpRecord::with(['operator', 'slaughterOrder'])
            ->when($request->filled('ccp'), fn ($q) => $q->where('ccp', $request->input('ccp')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('releve_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('releve_at', '<=', $request->input('to')))
            ->latest('releve_at')->latest('id')
            ->paginate((int) setting('general.items_per_page', 15))
            ->withQueryString();

        return view('slaughter.registres.ccp', compact('records'));
    }

    public function ccpCreate()
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $orders = SlaughterOrder::whereIn('status', ['planifie', 'en_cours', 'termine', 'bloque'])
            ->latest('planned_date')->latest('id')
            ->take(30)
            ->get();

        return view('slaughter.registres.ccp-create', compact('orders'));
    }

    public function ccpStore(Request $request)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'ccp'                 => 'required|in:' . implode(',', CcpRecord::CCPS),
            'slaughter_order_id'  => 'nullable|integer|exists:slaughter_orders,id',
            'equipment_ref'       => 'nullable|string|max:50',
            'corrective_action'   => 'nullable|string|max:2000',
            // Mesures selon le CCP (le serveur ne garde que celles du CCP choisi)
            'carcasses_total'     => 'required_if:ccp,' . CcpRecord::CCP2 . '|nullable|integer|min:1',
            'carcasses_souillees' => 'required_if:ccp,' . CcpRecord::CCP2 . '|nullable|integer|min:0|lte:carcasses_total',
            'temperature_coeur'   => 'required_if:ccp,' . CcpRecord::CCP3 . '|nullable|numeric|min:-60|max:120',
            'point'               => 'required_if:ccp,' . CcpRecord::CCP4 . '|nullable|in:' . implode(',', array_keys(TemperatureLog::POINTS)),
            'temperature'         => 'required_if:ccp,' . CcpRecord::CCP4 . '|nullable|numeric|min:-60|max:120',
            'declared_conforme'   => 'required_if:ccp,' . CcpRecord::CCP1 . '|nullable|in:0,1',
        ]);

        // Ne garder QUE les mesures du CCP sélectionné.
        $declared = null;
        $mesures  = match ($validated['ccp']) {
            CcpRecord::CCP2 => [
                'carcasses_total'     => (int) $validated['carcasses_total'],
                'carcasses_souillees' => (int) $validated['carcasses_souillees'],
            ],
            CcpRecord::CCP3 => ['temperature_coeur' => (float) $validated['temperature_coeur']],
            CcpRecord::CCP4 => [
                'point'       => $validated['point'],
                'temperature' => (float) $validated['temperature'],
            ],
            default => [],
        };

        if ($validated['ccp'] === CcpRecord::CCP1) {
            $declared = (bool) $validated['declared_conforme'];
            $mesures  = ['appreciation' => $declared ? 'conforme' : 'non_conforme'];
        }

        $action = app(RecordCcp::class);

        // Le serveur re-tranche : pré-évaluation de la conformité pour
        // exiger l'action corrective AVANT toute écriture (comme la sync).
        $conforme = $action->evaluate($validated['ccp'], $mesures, $declared);
        if (! $conforme && blank($validated['corrective_action'] ?? null)) {
            return back()->withErrors([
                'corrective_action' => __('Une action corrective est obligatoire pour un CCP non conforme.'),
            ])->withInput();
        }

        $record = $action->execute([
            'ccp'                => $validated['ccp'],
            'slaughter_order_id' => $validated['slaughter_order_id'] ?? null,
            'equipment_ref'      => $validated['equipment_ref'] ?? null,
            'mesures'            => $mesures,
            'conforme'           => $declared,
            'corrective_action'  => $validated['corrective_action'] ?? null,
            'operator_id'        => Auth::id(),
            'releve_at'          => now(),
        ]);

        return redirect()->route('slaughter.registres.ccp')
            ->with($record->conforme ? 'success' : 'error', $record->conforme
                ? __('Relevé CCP enregistré — conforme.')
                : __('Relevé CCP enregistré — NON CONFORME : alerte qualité déclenchée.')
                  . ($record->slaughter_order_id ? ' ' . __('L\'ordre lié a été bloqué.') : ''));
    }

    // ──────────────────────────────────────────────
    // REGISTRE DES TEMPÉRATURES (E4)
    // ──────────────────────────────────────────────

    public function temperatureIndex(Request $request)
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $logs = TemperatureLog::with('operator')
            ->when($request->filled('point'), fn ($q) => $q->where('point', $request->input('point')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('releve_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('releve_at', '<=', $request->input('to')))
            ->latest('releve_at')->latest('id')
            ->paginate((int) setting('general.items_per_page', 15))
            ->withQueryString();

        // Indicateur « relevés du jour : X / N requis » par point de contrôle.
        $requiredPerDay = (int) setting('abattoir.temp_readings_per_day', 2);
        $todayCounts    = TemperatureLog::whereDate('releve_at', today())
            ->selectRaw('point, COUNT(*) as total')
            ->groupBy('point')
            ->pluck('total', 'point');

        return view('slaughter.registres.temperatures', compact('logs', 'requiredPerDay', 'todayCounts'));
    }

    public function temperatureStore(Request $request)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'point'             => 'required|in:' . implode(',', array_keys(TemperatureLog::POINTS)),
            'equipment_ref'     => 'nullable|string|max:50',
            'temperature'       => 'required|numeric|min:-60|max:120',
            'corrective_action' => 'nullable|string|max:2000',
        ]);

        $log = app(RecordTemperatureLog::class)->execute(array_merge($validated, [
            'operator_id' => Auth::id(),
            'releve_at'   => now(),
        ]));

        return redirect()->route('slaughter.registres.temperatures')
            ->with($log->conforme ? 'success' : 'error', $log->conforme
                ? __('Relevé enregistré — :temp °C, conforme.', ['temp' => $log->temperature])
                : __('Relevé enregistré — :temp °C, HORS SEUIL : alerte déclenchée.', ['temp' => $log->temperature]));
    }

    // ──────────────────────────────────────────────
    // REGISTRE NETTOYAGE / DÉSINFECTION (E7)
    // ──────────────────────────────────────────────

    public function cleaningIndex(Request $request)
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $logs = CleaningLog::with('operator')
            ->when($request->filled('zone'), fn ($q) => $q->where('zone', $request->input('zone')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('done_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('done_at', '<=', $request->input('to')))
            ->latest('done_at')->latest('id')
            ->paginate((int) setting('general.items_per_page', 15))
            ->withQueryString();

        return view('slaughter.registres.nettoyage', compact('logs'));
    }

    public function cleaningStore(Request $request)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'zone'         => 'required|in:' . implode(',', array_keys(CleaningLog::ZONES)),
            'product_used' => 'required|string|max:100',
            'dosage'       => 'nullable|string|max:50',
            'notes'        => 'nullable|string|max:1000',
            'photo'        => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        CleaningLog::create([
            'zone'         => $validated['zone'],
            'product_used' => $validated['product_used'],
            'dosage'       => $validated['dosage'] ?? null,
            'notes'        => $validated['notes'] ?? null,
            'photo_path'   => $request->hasFile('photo')
                ? $request->file('photo')->store('cleaning', 'public')
                : null,
            'operator_id'  => Auth::id(),
            'done_at'      => now(),
            'synced_at'    => now(),
        ]);

        return redirect()->route('slaughter.registres.nettoyage')
            ->with('success', __('Opération de nettoyage enregistrée — :zone.', [
                'zone' => CleaningLog::ZONES[$validated['zone']] ?? $validated['zone'],
            ]));
    }

    // ──────────────────────────────────────────────
    // EXPORT PDF — registre opposable (releve_at + synced_at)
    // ──────────────────────────────────────────────

    public function export(Request $request)
    {
        if (Gate::denies('abattoir.L')) return back()->with('error', 'Accès restreint.');

        $validated = $request->validate([
            'type' => 'required|in:temperatures,ccp,nettoyage',
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to   = $validated['to'] ?? now()->toDateString();

        $farm = \App\Models\Farm::find(session('current_farm_id')) ?? \App\Models\Farm::first();

        $meta = [
            'farm'        => $farm?->name ?? setting('general.company_name', config('app.name')),
            'from'        => \Carbon\Carbon::parse($from),
            'to'          => \Carbon\Carbon::parse($to),
            'generatedAt' => now(),
        ];

        [$view, $data, $filename] = match ($validated['type']) {
            'temperatures' => [
                'slaughter.pdf.registre-temperatures',
                ['logs' => TemperatureLog::with('operator')
                    ->whereDate('releve_at', '>=', $from)->whereDate('releve_at', '<=', $to)
                    ->orderBy('releve_at')->get()],
                "registre-temperatures-{$from}-{$to}.pdf",
            ],
            'ccp' => [
                'slaughter.pdf.registre-ccp',
                ['records' => CcpRecord::with(['operator', 'slaughterOrder'])
                    ->whereDate('releve_at', '>=', $from)->whereDate('releve_at', '<=', $to)
                    ->orderBy('releve_at')->get()],
                "registre-ccp-{$from}-{$to}.pdf",
            ],
            'nettoyage' => [
                'slaughter.pdf.registre-nettoyage',
                ['logs' => CleaningLog::with('operator')
                    ->whereDate('done_at', '>=', $from)->whereDate('done_at', '<=', $to)
                    ->orderBy('done_at')->get()],
                "registre-nettoyage-{$from}-{$to}.pdf",
            ],
        };

        $pdf = \Pdf::loadView($view, array_merge($data, ['meta' => $meta]))
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }
}
