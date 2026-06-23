<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Rendements") }} {{ $year }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        h2 { font-size: 13px; margin: 16px 0 6px 0; text-transform: uppercase; color: #166534; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpis { display: flex; gap: 12px; margin-bottom: 16px; }
        .kpi { border: 1px solid #e2e8f0; padding: 8px 14px; flex: 1; background: #f8fafc; }
        .kpi-val { font-size: 18px; font-weight: bold; color: #166534; }
        .kpi-lbl { font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .pos { color: #047857; font-weight: bold; }
        .neg { color: #be123c; font-weight: bold; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Analyse des Rendements") }} — {{ $year }}</h1>
    <div class="subtitle">{{ __("Production végétale") }} · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}</div>

    <div class="kpis">
        <div class="kpi"><div class="kpi-val">{{ $cycles->count() }}</div><div class="kpi-lbl">{{ __("Cycles récoltés") }}</div></div>
        <div class="kpi"><div class="kpi-val">{{ number_format($totalArea, 1) }} ha</div><div class="kpi-lbl">{{ __("Surface totale") }}</div></div>
        <div class="kpi"><div class="kpi-val">{{ number_format($totalHarvested, 0) }} kg</div><div class="kpi-lbl">{{ __("Production totale") }}</div></div>
        <div class="kpi"><div class="kpi-val">{{ number_format($avgYieldPerHa, 0) }} kg/ha</div><div class="kpi-lbl">{{ __("Rendement moyen") }}</div></div>
    </div>

    <h2>{{ __("Synthèse par culture") }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Culture") }}</th>
                <th>{{ __("Cycles") }}</th>
                <th>{{ __("Surface (ha)") }}</th>
                <th>{{ __("Production (kg)") }}</th>
                <th>{{ __("Rdt kg/ha") }}</th>
                <th>{{ __("Marge nette") }} ({{ setting('general.currency', 'GNF') }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($byCrop as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['cycles_count'] }}</td>
                <td>{{ number_format($row['total_ha'], 2) }}</td>
                <td>{{ number_format($row['total_kg'], 0) }}</td>
                <td>{{ number_format($row['yield_per_ha'], 0) }}</td>
                <td class="{{ $row['net_margin'] >= 0 ? 'pos' : 'neg' }}">{{ number_format($row['net_margin'], 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ __("Détail des cycles") }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Culture") }}</th>
                <th>{{ __("Parcelle") }}</th>
                <th>{{ __("Surface") }}</th>
                <th>{{ __("Semis") }}</th>
                <th>{{ __("Récolte (kg)") }}</th>
                <th>{{ __("Rdt kg/ha") }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cycles as $cycle)
            <tr>
                <td>{{ $cycle->crop_name }}{{ $cycle->variety ? ' ('.$cycle->variety.')' : '' }}</td>
                <td>{{ $cycle->plot->name ?? '—' }}</td>
                <td>{{ number_format((float)$cycle->area_used_ha, 2) }} ha</td>
                <td>{{ $cycle->planting_date?->format('d/m/Y') }}</td>
                <td>{{ number_format($cycle->total_harvested, 0) }}</td>
                <td>{{ number_format($cycle->yield_per_ha, 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">{{ setting('general.farm_name', 'ERP Avicole') }} · {{ __("Rapport généré le") }} {{ now()->format('d/m/Y à H:i') }}</div>
</body>
</html>
