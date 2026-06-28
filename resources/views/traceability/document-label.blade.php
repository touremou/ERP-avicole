<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Étiquette — {{ $code }}</title>
    @php
        $themes = [
            'lime'   => '#3f6212',
            'green'  => '#166534',
            'indigo' => '#3730a3',
            'slate'  => '#0f172a',
        ];
        $accentColor = $themes[$accent ?? 'slate'] ?? '#0f172a';

        // Configuration (Réglages > Étiquettes) + override par querystring.
        $copies      = max(1, min(60, (int) request('copies', (int) setting('etiquettes.copies', 1))));
        $columns     = max(1, min(4, (int) request('cols', (int) setting('etiquettes.columns', 2))));
        $showFarm    = (bool) setting('etiquettes.show_farm', true);
        $showCaption = (bool) setting('etiquettes.show_caption', true);
    @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #e2e8f0; padding: 20px; }
        .label { background: #fff; border: 2px solid {{ $accentColor }}; border-radius: 12px; padding: 18px; display: flex; gap: 16px; align-items: center; }
        .qr { width: 140px; height: 140px; flex-shrink: 0; }
        .qr img { width: 100%; height: 100%; display: block; }
        .info { flex: 1; min-width: 0; }
        .farm { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: {{ $accentColor }}; font-weight: 800; }
        .title { font-size: 12px; font-weight: 700; color: #475569; margin-top: 2px; }
        .code { font-size: 22px; font-weight: 900; font-style: italic; line-height: 1.1; margin: 2px 0 8px; }
        .meta { font-size: 11px; color: #334155; line-height: 1.6; }
        .meta b { font-weight: 800; }
        .scan { margin-top: 10px; font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        @media print { body { background: #fff; padding: 0; } .actions { display: none; } @page { margin: 8mm; } }
    </style>
    @include('traceability._label-styles')
</head>
<body>
    <div class="label-sheet" style="--cols: {{ $columns }}">
        @for($i = 0; $i < $copies; $i++)
        <div class="label">
            <div class="qr"><img src="{{ $qr }}" alt="QR {{ $code }}"></div>
            <div class="info">
                @if($showFarm)<div class="farm">{{ $farm ?? __('Traçabilité') }}</div>@endif
                <div class="title">{{ $title }}</div>
                <div class="code">{{ $code }}</div>
                <div class="meta">
                    @foreach($rows as $row)
                    <div>{{ $row[0] }} : <b>{{ $row[1] }}</b></div>
                    @endforeach
                </div>
                @if($showCaption)<div class="scan">{{ __('Scanner pour la traçabilité') }}</div>@endif
            </div>
        </div>
        @endfor
    </div>

    @if(setting('etiquettes.show_printed_at', false))
        <div class="printed-at">{{ __('Imprimé le') }} {{ now()->format('d/m/Y H:i') }}</div>
    @endif

    @include('traceability._print-controls')
</body>
</html>
