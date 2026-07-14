<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Registre nettoyage & désinfection") }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; font-size: 9px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Registre nettoyage & désinfection") }}</h1>
    <div class="subtitle">
        {{ __("Ferme") }} : {{ $meta['farm'] }} ·
        {{ __("Période") }} : {{ $meta['from']->format('d/m/Y') }} {{ __("au") }} {{ $meta['to']->format('d/m/Y') }} ·
        {{ __("Généré le") }} {{ $meta['generatedAt']->format('d/m/Y H:i') }}
    </div>

    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Zone") }}</th>
                <th>{{ __("Produit utilisé") }}</th>
                <th>{{ __("Dosage") }}</th>
                <th>{{ __("Notes") }}</th>
                <th>{{ __("Opérateur") }}</th>
                <th>{{ __("Effectué le") }}</th>
                <th>{{ __("Synchronisé le") }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
            <tr>
                <td>{{ __(\App\Models\CleaningLog::ZONES[$log->zone] ?? $log->zone) }}</td>
                <td>{{ $log->product_used }}</td>
                <td>{{ $log->dosage ?: '—' }}</td>
                <td>{{ $log->notes ?: '—' }}</td>
                <td>{{ $log->operator?->name ?? '—' }}</td>
                <td>{{ $log->done_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td>{{ $log->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="7" class="muted">{{ __("Aucune opération sur la période.") }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ __("Document généré par AviSmart — registre HACCP") }}</div>
</body>
</html>
