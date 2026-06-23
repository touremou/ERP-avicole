<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Transformations") }} {{ $year }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        h2 { font-size: 13px; margin: 16px 0 6px 0; text-transform: uppercase; color: #065f46; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpis { display: flex; gap: 12px; margin-bottom: 16px; }
        .kpi { border: 1px solid #e2e8f0; padding: 8px 14px; flex: 1; background: #f8fafc; }
        .kpi-val { font-size: 18px; font-weight: bold; color: #065f46; }
        .kpi-lbl { font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Efficacité Transformation") }} — {{ $year }}</h1>
    <div class="subtitle">{{ __("Production végétale") }} · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}</div>

    <div class="kpis">
        <div class="kpi"><div class="kpi-val">{{ number_format($totalInput, 0) }} kg</div><div class="kpi-lbl">{{ __("Entrée totale") }}</div></div>
        <div class="kpi"><div class="kpi-val">{{ number_format($totalOutput, 0) }} kg</div><div class="kpi-lbl">{{ __("Sortie totale") }}</div></div>
        <div class="kpi"><div class="kpi-val">{{ number_format($avgYield, 1) }}%</div><div class="kpi-lbl">{{ __("Rendement moyen") }}</div></div>
        <div class="kpi"><div class="kpi-val">{{ number_format($totalValue, 0) }}</div><div class="kpi-lbl">{{ __("Valeur") }} ({{ setting('general.currency', 'GNF') }})</div></div>
    </div>

    <h2>{{ __("Synthèse par type") }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Type") }}</th>
                <th>{{ __("Lots") }}</th>
                <th>{{ __("Entrée (kg)") }}</th>
                <th>{{ __("Sortie (kg)") }}</th>
                <th>{{ __("Rendement") }}</th>
                <th>{{ __("Valeur") }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($byType as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['count'] }}</td>
                <td>{{ number_format($row['input'], 0) }}</td>
                <td>{{ number_format($row['output'], 0) }}</td>
                <td>{{ number_format($row['yield'], 1) }}%</td>
                <td>{{ number_format($row['value'], 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ __("Détail des lots") }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Lot") }}</th>
                <th>{{ __("Date") }}</th>
                <th>{{ __("Entrée (kg)") }}</th>
                <th>{{ __("Sortie (kg)") }}</th>
                <th>{{ __("Rendement") }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transformations->sortByDesc('production_date') as $t)
            <tr>
                <td>{{ $t->input_product }} → {{ $t->output_product }}</td>
                <td>{{ $t->production_date?->format('d/m/Y') }}</td>
                <td>{{ number_format((float)$t->input_quantity, 0) }}</td>
                <td>{{ number_format((float)$t->output_quantity, 0) }}</td>
                <td>{{ number_format((float)$t->yield_percent, 1) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">{{ setting('general.farm_name', 'ERP Avicole') }} · {{ __("Rapport généré le") }} {{ now()->format('d/m/Y à H:i') }}</div>
</body>
</html>
