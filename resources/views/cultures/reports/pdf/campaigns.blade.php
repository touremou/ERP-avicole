<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Campagnes") }} {{ $year }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        h2 { font-size: 13px; margin: 16px 0 6px 0; text-transform: uppercase; color: #0369a1; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .pos { color: #047857; font-weight: bold; }
        .neg { color: #be123c; font-weight: bold; }
        .campaign-header { font-size: 14px; font-weight: bold; text-transform: uppercase; margin-top: 12px; }
        .campaign-season { font-size: 9px; color: #0369a1; text-transform: uppercase; margin-bottom: 6px; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Bilan des Campagnes") }} — {{ $year }}</h1>
    <div class="subtitle">{{ __("Production végétale") }} · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}</div>

    @forelse($campaigns as $campaign)
    @php
        $harvested = $campaign->total_harvested;
        $totalRevenue = (float) $campaign->cycles->sum('total_revenue');
        $totalMargin = (float) $campaign->cycles->sum(fn($c) => $c->net_margin);
    @endphp
    <div class="campaign-header">{{ $campaign->name }}</div>
    <div class="campaign-season">{{ $campaign->season_label }} · {{ $campaign->status_label }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Cycles") }}</th>
                <th>{{ __("Récolté (kg)") }}</th>
                <th>{{ __("Revenus") }} ({{ setting('general.currency', 'GNF') }})</th>
                <th>{{ __("Marge nette") }} ({{ setting('general.currency', 'GNF') }})</th>
                @if($campaign->target_production_t)<th>{{ __("Avancement") }}</th>@endif
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $campaign->cycles->count() }}</td>
                <td>{{ number_format($harvested, 0) }}</td>
                <td>{{ number_format($totalRevenue, 0) }}</td>
                <td class="{{ $totalMargin >= 0 ? 'pos' : 'neg' }}">{{ number_format($totalMargin, 0) }}</td>
                @if($campaign->target_production_t)<td>{{ $campaign->progress_percent ?? '—' }}%</td>@endif
            </tr>
        </tbody>
    </table>
    @empty
    <p>{{ __("Aucune campagne pour cette année.") }}</p>
    @endforelse

    <div class="footer">{{ setting('general.farm_name', 'ERP Avicole') }} · {{ __("Rapport généré le") }} {{ now()->format('d/m/Y à H:i') }}</div>
</body>
</html>
