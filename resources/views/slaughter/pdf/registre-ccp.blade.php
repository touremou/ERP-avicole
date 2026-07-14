<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Registre CCP") }}</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; font-size: 9px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .ok { color: #047857; font-weight: bold; }
        .ko { color: #b91c1c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ __("Registre CCP — Points critiques HACCP") }}</h1>
    <div class="subtitle">
        {{ __("Ferme") }} : {{ $meta['farm'] }} ·
        {{ __("Période") }} : {{ $meta['from']->format('d/m/Y') }} {{ __("au") }} {{ $meta['to']->format('d/m/Y') }} ·
        {{ __("Généré le") }} {{ $meta['generatedAt']->format('d/m/Y H:i') }}
    </div>

    <table class="data">
        <thead>
            <tr>
                <th>{{ __("Point critique") }}</th>
                <th>{{ __("Ordre lié") }}</th>
                <th>{{ __("Équipement") }}</th>
                <th>{{ __("Mesures") }}</th>
                <th>{{ __("Conforme") }}</th>
                <th>{{ __("Action corrective") }}</th>
                <th>{{ __("Opérateur") }}</th>
                <th>{{ __("Relevé le") }}</th>
                <th>{{ __("Synchronisé le") }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $rec)
            <tr>
                <td>{{ \App\Models\CcpRecord::labelFor($rec->ccp) }}</td>
                <td>
                    {{ $rec->slaughterOrder?->order_number ?? '—' }}
                    @if($rec->slaughterOrder?->isBlocked()) <span class="ko">({{ __("BLOQUÉ") }})</span> @endif
                </td>
                <td>{{ $rec->equipment_ref ?: '—' }}</td>
                <td>
                    @foreach($rec->mesures ?? [] as $k => $v)
                        {{ str_replace('_', ' ', $k) }} : {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if(! $loop->last)<br>@endif
                    @endforeach
                </td>
                <td class="{{ $rec->conforme ? 'ok' : 'ko' }}">{{ $rec->conforme ? __('Oui') : __('NON') }}</td>
                <td>{{ $rec->corrective_action ?: '—' }}</td>
                <td>{{ $rec->operator?->name ?? '—' }}</td>
                <td>{{ $rec->releve_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td>{{ $rec->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="9" class="muted">{{ __("Aucun relevé sur la période.") }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ __("Document généré par AviSmart — registre HACCP") }}</div>
</body>
</html>
