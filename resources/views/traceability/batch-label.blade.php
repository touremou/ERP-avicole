<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Étiquette — Lot {{ $batch->code }}</title>
    @php
        $cfg = \App\Support\LabelConfig::current();
        $showBarcode = $cfg['showBarcode'] && ! empty($barcode);
    @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #e2e8f0; padding: 20px; }
        .label { background: #fff; border: 2px solid #0f172a; border-radius: 12px; padding: 18px; }
        .qr { width: 140px; height: 140px; flex-shrink: 0; }
        .qr img { width: 100%; height: 100%; display: block; }
        .info { flex: 1; min-width: 0; }
        .farm { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; font-weight: 700; }
        .code { font-size: 24px; font-weight: 900; font-style: italic; line-height: 1.1; margin: 2px 0 8px; }
        .meta { font-size: 11px; color: #334155; line-height: 1.6; }
        .meta b { font-weight: 800; }
        .scan { margin-top: 10px; font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        @page { size: {{ $cfg['pageSize'] }}; margin: 8mm; }
        @media print { body { background: #fff; padding: 0; } .label { border-color: #000; } .actions { display: none; } }
    </style>
    @include('traceability._label-styles')
</head>
<body>
    <div class="label-sheet fmt-{{ $cfg['format'] }} {{ $cfg['labelHeight'] > 0 ? 'has-fixed-h' : '' }}"
         style="--label-w: {{ $cfg['labelWidth'] }}mm; --label-gap: {{ $cfg['labelGap'] }}mm;{{ $cfg['labelHeight'] > 0 ? ' --label-h: '.$cfg['labelHeight'].'mm;' : '' }}">
        @for($i = 0; $i < $cfg['copies']; $i++)
        <div class="label">
            <div class="head">
                @if($cfg['showQr'])<div class="qr"><img src="{{ $qr }}" alt="QR {{ $batch->code }}"></div>@endif
                <div class="info">
                    @if($cfg['showFarm'])<div class="farm">{{ $batch->farm?->name ?? __('Traçabilité') }}</div>@endif
                    <div class="code">{{ $batch->code }}</div>
                    <div class="meta">
                        <div><b>{{ $batch->species?->name_fr ?? '—' }}</b> · {{ $batch->productionType?->name_fr ?? '—' }}</div>
                        <div>{{ __('Bâtiment') }} : <b>{{ $batch->building?->name ?? '—' }}</b></div>
                        <div>{{ __('Arrivée') }} : <b>{{ $batch->arrival_date?->format('d/m/Y') ?? '—' }}</b></div>
                    </div>
                    @if($cfg['showCaption'])<div class="scan">{{ __('Scanner pour la traçabilité') }}</div>@endif
                </div>
            </div>
            @if($showBarcode)<div class="barcode">{!! $barcode !!}</div>@endif
        </div>
        @endfor
    </div>

    @if($cfg['showPrintedAt'])
        <div class="printed-at">{{ __('Imprimé le') }} {{ now()->format('d/m/Y H:i') }}</div>
    @endif

    @include('traceability._print-controls')
</body>
</html>
