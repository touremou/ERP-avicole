<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Rapport GMQ") }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpi-box { background: #047857; color: #fff; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .kpi-box .label { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7; }
        .kpi-box .value { font-size: 22px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .gmq-good { color: #047857; font-weight: bold; }
        .gmq-mid { color: #b45309; font-weight: bold; }
        .gmq-bad { color: #be123c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Rapport GMQ — Engraissement") }}</h1>
    <div class="subtitle">{{ __("Gain Moyen Quotidien par lot (Ruminants, Porcins, Lapins)") }} · {{ __("Statut") }} : {{ $statusFilter === 'all' ? __("Tous") : $statusFilter }} · {{ __("Généré le") }} {{ now()->format('d/m/Y H:i') }}</div>

    @if($avgGmq)
    <div class="kpi-box">
        <div class="label">{{ __("GMQ moyen — ensemble des lots") }}</div>
        <div class="value">{{ number_format($avgGmq, 0) }} {{ __("g/jour") }}</div>
    </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>{{ __("Lot") }}</th>
                <th>{{ __("Espèce") }}</th>
                <th>{{ __("Bâtiment") }}</th>
                <th>{{ __("Âge (j)") }}</th>
                <th>{{ __("Poids départ") }}</th>
                <th>{{ __("Dernier poids") }}</th>
                <th>{{ __("GMQ (g/j)") }}</th>
                <th>{{ __("Naissances") }}</th>
                <th>{{ __("Sevrages") }}</th>
                <th>{{ __("Portée moy.") }}</th>
                <th>{{ __("Taux sevrage") }}</th>
                <th>{{ __("Statut") }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($batchStats as $stat)
            @php $batch = $stat['batch']; $gmq = $stat['gmq']; @endphp
            <tr>
                <td>{{ $batch->code }}</td>
                <td>{{ $batch->species?->name_fr ?? __("Ruminant") }}</td>
                <td>{{ $batch->building?->name ?? '—' }}</td>
                <td>{{ $stat['age_days'] }}</td>
                <td>{{ $stat['start_weight'] ? number_format($stat['start_weight'], 3).' kg' : '—' }}</td>
                <td>{{ $stat['last_weight'] ? number_format($stat['last_weight'], 3).' kg' : '—' }}</td>
                <td>
                    @if($gmq !== null)
                        <span class="{{ $gmq >= 150 ? 'gmq-good' : ($gmq >= 80 ? 'gmq-mid' : 'gmq-bad') }}">{{ number_format($gmq, 0) }}</span>
                    @else
                        <span class="muted">{{ __("N/A") }}</span>
                    @endif
                </td>
                <td>{{ ($stat['total_born'] ?? 0) > 0 ? number_format($stat['total_born']) : '—' }}</td>
                <td>{{ ($stat['total_weaned'] ?? 0) > 0 ? number_format($stat['total_weaned']) : '—' }}</td>
                <td>{{ $stat['avg_litter_size'] ?? '—' }}</td>
                <td>
                    @if(($stat['weaning_rate'] ?? null) !== null)
                        <span class="{{ $stat['weaning_rate'] >= 90 ? 'gmq-good' : ($stat['weaning_rate'] >= 75 ? 'gmq-mid' : 'gmq-bad') }}">{{ number_format($stat['weaning_rate'], 1) }}%</span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>{{ $batch->status }}</td>
            </tr>
            @empty
            <tr><td colspan="12" class="muted">{{ __("Aucun lot trouvé.") }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">AviSmart ERP — {{ __("Rapport généré automatiquement") }}</div>
</body>
</html>
