<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Compte de résultat") }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpi-row { width: 100%; margin-bottom: 16px; }
        .kpi-row td { border: none; padding: 0 6px 0 0; }
        .kpi-box { padding: 12px 16px; border-radius: 8px; color: #fff; }
        .kpi-box.revenue { background: #047857; }
        .kpi-box.costs { background: #be123c; }
        .kpi-box.net-pos { background: #047857; }
        .kpi-box.net-neg { background: #be123c; }
        .kpi-box .label { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7; }
        .kpi-box .value { font-size: 22px; font-weight: bold; }
        h2.section { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin: 18px 0 8px 0; color: #1e293b; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        table.data td.amount { text-align: right; }
        .total-row td { font-weight: bold; background: #f8fafc; }
        .text-pos { color: #047857; }
        .text-neg { color: #be123c; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Compte de résultat") }}</h1>
    <div class="subtitle">{{ __("P&L consolidé — toutes activités") }} · {{ __("Période") }} : {{ $from->format('d/m/Y') }} {{ __("au") }} {{ $to->format('d/m/Y') }} · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}</div>

    <table class="kpi-row">
        <tr>
            <td style="width: 33%;">
                <div class="kpi-box revenue">
                    <div class="label">{{ __("Produits") }}</div>
                    <div class="value">{{ number_format($totalRevenue) }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box costs">
                    <div class="label">{{ __("Charges") }}</div>
                    <div class="value">{{ number_format($totalCosts) }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box {{ $netResult >= 0 ? 'net-pos' : 'net-neg' }}">
                    <div class="label">{{ __("Résultat net") }} ({{ __("marge") }} {{ $marginPct }}%)</div>
                    <div class="value">{{ number_format($netResult) }}</div>
                </div>
            </td>
        </tr>
    </table>

    <h2 class="section">{{ __("Produits") }}</h2>
    <table class="data">
        <thead>
            <tr><th>{{ __("Source") }}</th><th class="amount" style="text-align:right;">{{ __("Montant") }}</th></tr>
        </thead>
        <tbody>
            @forelse($revenue as $label => $amount)
            <tr><td>{{ $label }}</td><td class="amount">{{ number_format($amount) }}</td></tr>
            @empty
            <tr><td colspan="2" class="muted">{{ __("Aucun produit sur la période.") }}</td></tr>
            @endforelse
            <tr class="total-row"><td>{{ __("Total produits") }}</td><td class="amount">{{ number_format($totalRevenue) }}</td></tr>
        </tbody>
    </table>

    <h2 class="section">{{ __("Charges") }}</h2>
    <table class="data">
        <thead>
            <tr><th>{{ __("Poste") }}</th><th class="amount" style="text-align:right;">{{ __("Montant") }}</th></tr>
        </thead>
        <tbody>
            @foreach($costs as $label => $amount)
            <tr><td>{{ $label }}</td><td class="amount">{{ number_format($amount) }}</td></tr>
            @endforeach
            <tr class="total-row"><td>{{ __("Total charges") }}</td><td class="amount">{{ number_format($totalCosts) }}</td></tr>
        </tbody>
    </table>

    <h2 class="section">{{ __("Marge directe par espèce") }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Espèce") }}</th>
                <th class="amount" style="text-align:right;">{{ __("Produits") }}</th>
                <th class="amount" style="text-align:right;">{{ __("Coûts directs") }}</th>
                <th class="amount" style="text-align:right;">{{ __("Marge directe") }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($speciesMargin as $row)
            <tr>
                <td>{{ $row['icon'] }} {{ $row['species'] }}</td>
                <td class="amount text-pos">{{ number_format($row['revenue']) }}</td>
                <td class="amount text-neg">{{ number_format($row['cost']) }}</td>
                <td class="amount {{ $row['margin'] >= 0 ? '' : 'text-neg' }}">{{ number_format($row['margin']) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="muted">{{ __("Aucune activité traçable par espèce sur la période.") }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(count($cropMargin ?? []))
    <h2 class="section">{{ __("Marge directe par culture") }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Culture") }}</th>
                <th class="amount" style="text-align:right;">{{ __("Produits") }}</th>
                <th class="amount" style="text-align:right;">{{ __("Coûts directs") }}</th>
                <th class="amount" style="text-align:right;">{{ __("Marge directe") }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cropMargin as $row)
            <tr>
                <td>🌱 {{ $row['crop'] }}</td>
                <td class="amount text-pos">{{ number_format($row['revenue']) }}</td>
                <td class="amount text-neg">{{ number_format($row['cost']) }}</td>
                <td class="amount {{ $row['margin'] >= 0 ? '' : 'text-neg' }}">{{ number_format($row['margin']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">{{ __("AviSmart ERP — Rapport généré automatiquement") }}</div>
</body>
</html>
