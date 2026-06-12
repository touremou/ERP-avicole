<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Flux de Trésorerie") }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpi-row { width: 100%; margin-bottom: 16px; }
        .kpi-row td { border: none; padding: 0 6px 0 0; }
        .kpi-box { padding: 12px 16px; border-radius: 8px; color: #fff; }
        .kpi-box.dark { background: #172554; }
        .kpi-box.blue { background: #1d4ed8; }
        .kpi-box.orange { background: #b45309; }
        .kpi-box.rose { background: #be123c; }
        .kpi-box .label { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7; }
        .kpi-box .value { font-size: 20px; font-weight: bold; }
        h2.section { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin: 18px 0 8px 0; color: #1e293b; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        table.data td.amount { text-align: right; }
        .total-row td { font-weight: bold; background: #f8fafc; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Analyse Financière — Flux de Trésorerie") }}</h1>
    <div class="subtitle">
        {{ __("Alimentation · Santé · Acquisition · Coût par tête") }} ·
        {{ __("Statut") }} : {{ ['all' => __("Tous"), 'actif' => __("Actifs"), 'termine' => __("Terminés"), 'clos' => __("Archives")][$statusFilter] ?? $statusFilter }} ·
        {{ __("Espèce") }} : {{ $speciesFilter === 'all' ? __("Toutes") : (optional($speciesList->firstWhere('id', (int) $speciesFilter))->name_fr ?? $speciesFilter) }} ·
        @if($useDateRange)
            {{ __("Période") }} : {{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}
        @else
            {{ __("Année") }} : {{ $currentYear }} {{ $monthFilter !== 'all' ? '· ' . __("Mois") . ' : ' . ($months[$monthFilter] ?? $monthFilter) : '' }}
        @endif
        · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}
    </div>

    @if(!empty($monthlyData))
    <table class="kpi-row">
        <tr>
            <td style="width: 25%;">
                <div class="kpi-box dark">
                    <div class="label">{{ __("Coût total consolidé") }}</div>
                    <div class="value">{{ number_format($globalStats['total_cost'], 0, ',', ' ') }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-box blue">
                    <div class="label">{{ __("Coût / tête") }}</div>
                    <div class="value">{{ number_format($globalStats['cost_per_head'], 0, ',', ' ') }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-box orange">
                    <div class="label">{{ __("Charge aliment") }} ({{ $globalStats['feed_pct'] }}%)</div>
                    <div class="value">{{ number_format($globalStats['feed_cost'], 0, ',', ' ') }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-box rose">
                    <div class="label">{{ __("Invest. santé") }} ({{ $globalStats['health_pct'] }}%)</div>
                    <div class="value">{{ number_format($globalStats['health_cost'], 0, ',', ' ') }}</div>
                </div>
            </td>
        </tr>
    </table>

    @php
        $monthLabels = [0 => __('Plage personnalisée')] + $months;
    @endphp

    @foreach($monthLabels as $num => $name)
        @if(isset($monthlyData[$num]))
        @php
            $mRows  = $monthlyData[$num];
            $mTotal = collect($mRows)->sum('health') + collect($mRows)->sum('feed_cost');
        @endphp
        <h2 class="section">
            {{ $name }}
            @if(! $useDateRange) {{ $currentYear }} @endif
            — {{ count($mRows) }} {{ __("lot(s)") }} · {{ number_format($mTotal, 0, ',', ' ') }} GNF
        </h2>
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __("Lot") }}</th>
                    <th>{{ __("Bâtiment") }}</th>
                    <th>{{ __("Type") }}</th>
                    <th>{{ __("Statut") }}</th>
                    <th class="amount" style="text-align:right;">{{ __("Santé") }}</th>
                    <th class="amount" style="text-align:right;">{{ __("Aliment") }}</th>
                    <th class="amount" style="text-align:right;">{{ __("Conso (kg)") }}</th>
                    <th class="amount" style="text-align:right;">{{ __("Acquisition") }}</th>
                    <th class="amount" style="text-align:right;">{{ __("Total") }}</th>
                    <th class="amount" style="text-align:right;">{{ __("Coût/tête") }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($mRows as $data)
                @php
                    $totalCard    = ($data['health'] ?? 0) + ($data['feed_cost'] ?? 0);
                    $totalWithAcq = $totalCard + ($data['acquisition_cost'] ?? 0);
                    $costPerHead  = $data['batch']->initial_quantity > 0
                        ? $totalWithAcq / $data['batch']->initial_quantity : 0;
                @endphp
                <tr>
                    <td>{{ $data['batch']->code }}</td>
                    <td>{{ $data['batch']->building->name ?? 'ZONE LIBRE' }}</td>
                    <td>{{ strtoupper($data['batch']->type) }}</td>
                    <td>{{ $data['batch']->status }}</td>
                    <td class="amount">{{ number_format($data['health'] ?? 0, 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format($data['feed_cost'] ?? 0, 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format($data['feed_qty'] ?? 0, 1) }}</td>
                    <td class="amount">{{ number_format($data['acquisition_cost'] ?? 0, 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format($totalWithAcq, 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format($costPerHead, 0, ',', ' ') }}</td>
                </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="4">{{ __("Total") }} {{ $name }}</td>
                    <td class="amount">{{ number_format(collect($mRows)->sum('health'), 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format(collect($mRows)->sum('feed_cost'), 0, ',', ' ') }}</td>
                    <td class="amount">{{ number_format(collect($mRows)->sum('feed_qty'), 1) }}</td>
                    <td class="amount" colspan="3"></td>
                </tr>
            </tbody>
        </table>
        @endif
    @endforeach
    @else
    <p class="muted">{{ __("Aucun flux financier pour cette période.") }}</p>
    @endif

    <div class="footer">{{ __("AviSmart ERP — Rapport généré automatiquement") }}</div>
</body>
</html>
