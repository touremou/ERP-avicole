<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport Nurserie / Reproduction</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpi-row { width: 100%; margin-bottom: 16px; }
        .kpi-row td { border: none; padding: 0 6px 0 0; }
        .kpi-box { padding: 12px 16px; border-radius: 8px; color: #fff; }
        .kpi-box.dark { background: #172554; }
        .kpi-box.pink { background: #be185d; }
        .kpi-box.green { background: #047857; }
        .kpi-box .label { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7; }
        .kpi-box .value { font-size: 22px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .surv-good { color: #047857; font-weight: bold; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>Rapport Nurserie / Reproduction</h1>
    <div class="subtitle">Agnelage · chevrotage · sevrage · Période : {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} · Généré le {{ now()->format('d/m/Y H:i') }}</div>

    <table class="kpi-row">
        <tr>
            <td style="width: 33%;">
                <div class="kpi-box pink">
                    <div class="label">Naissances</div>
                    <div class="value">{{ number_format($totalBorn) }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box green">
                    <div class="label">Sevrages</div>
                    <div class="value">{{ number_format($totalWeaned) }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box dark">
                    <div class="label">Taux de sevrage moyen</div>
                    <div class="value">{{ $avgWeaningRate }}%</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Lot</th>
                <th>Espèce</th>
                <th>Naissances</th>
                <th>Sevrages</th>
                <th>Taux sevrage</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
            <tr>
                <td>{{ $r['batch']->code }}</td>
                <td>{{ $r['icon'] }} {{ $r['species'] }}</td>
                <td>{{ number_format($r['born']) }}</td>
                <td>{{ number_format($r['weaned']) }}</td>
                <td>
                    @if($r['weaning_rate'] !== null)
                        <span class="{{ $r['weaning_rate'] >= 80 ? 'surv-good' : '' }}">{{ $r['weaning_rate'] }}%</span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="muted">Aucune naissance ni sevrage saisi sur la période.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">AviSmart ERP — Rapport généré automatiquement</div>
</body>
</html>
