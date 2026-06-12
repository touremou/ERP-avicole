<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Performance Technique") }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .surv-good { color: #047857; font-weight: bold; }
        .surv-mid { color: #b45309; font-weight: bold; }
        .surv-bad { color: #be123c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Performance Technique & Viabilité") }}</h1>
    <div class="subtitle">{{ __("Analyse des indices de consommation et taux de survie") }} · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}</div>

    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Lot") }}</th>
                <th>{{ __("Bâtiment") }}</th>
                <th>{{ __("Âge (j)") }}</th>
                <th>{{ __("FCR") }}</th>
                <th>{{ __("Poids moyen (g)") }}</th>
                <th>{{ __("Gain quotidien (g/j)") }}</th>
                <th>{{ __("Stock vivant") }}</th>
                <th>{{ __("Initial") }}</th>
                <th>{{ __("Mortalité (têtes)") }}</th>
                <th>{{ __("Taux mortalité") }}</th>
                <th>{{ __("Statut") }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($stats as $batch)
            <tr>
                <td>{{ $batch['code'] }}</td>
                <td>{{ $batch['building'] }}</td>
                <td>{{ $batch['age'] }}</td>
                <td>{{ number_format($batch['fcr'] ?? 0, 2) }}</td>
                <td>{{ number_format($batch['avg_weight'] ?? 0, 0) }}</td>
                <td>{{ $batch['daily_gain'] ?? 0 }}</td>
                <td>{{ number_format($batch['current']) }}</td>
                <td>{{ number_format($batch['initial']) }}</td>
                <td>{{ number_format($batch['mortality_count']) }}</td>
                <td>
                    <span class="{{ $batch['status'] === 'Critique' ? 'surv-bad' : ($batch['status'] === 'Alerte' ? 'surv-mid' : 'surv-good') }}">{{ number_format($batch['mortality_rate'], 2) }}%</span>
                </td>
                <td>{{ $batch['status'] }}</td>
            </tr>
            @empty
            <tr><td colspan="11" class="muted">{{ __("Aucun lot actif en cours d'analyse.") }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ __("AviSmart ERP — Rapport généré automatiquement") }}</div>
</body>
</html>
