<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Étiquette œufs — {{ $batch?->code ?? '' }} {{ $eggProduction->production_date?->format('d/m/Y') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #e2e8f0; padding: 20px; }
        .label { width: 380px; margin: 0 auto; background: #fff; border: 2px solid #b45309; border-radius: 12px; padding: 18px; }
        .top { display: flex; gap: 16px; align-items: center; }
        .qr { width: 130px; height: 130px; flex-shrink: 0; }
        .qr img { width: 100%; height: 100%; display: block; }
        .info { flex: 1; min-width: 0; }
        .farm { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: #b45309; font-weight: 800; }
        .title { font-size: 20px; font-weight: 900; font-style: italic; line-height: 1.1; margin: 2px 0 8px; }
        .meta { font-size: 11px; color: #334155; line-height: 1.6; }
        .meta b { font-weight: 800; }
        .grades { margin-top: 14px; display: flex; gap: 6px; }
        .grade { flex: 1; text-align: center; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 6px 2px; }
        .grade .g { font-size: 10px; font-weight: 900; color: #b45309; }
        .grade .n { font-size: 13px; font-weight: 800; }
        .scan { margin-top: 10px; font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; text-align: center; }
        .actions { max-width: 380px; margin: 18px auto 0; text-align: center; }
        .actions button { background: #b45309; color: #fff; border: 0; padding: 12px 28px; border-radius: 10px; font-weight: 800; font-size: 13px; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .actions { display: none; }
            @page { margin: 8mm; }
        }
    </style>
</head>
<body>
    <div class="label">
        <div class="top">
            <div class="qr"><img src="{{ $qr }}" alt="QR traçabilité"></div>
            <div class="info">
                <div class="farm">{{ $batch?->farm?->name ?? __('Œufs frais') }}</div>
                <div class="title">{{ __('Œufs') }} · {{ $eggProduction->production_date?->format('d/m/Y') }}</div>
                <div class="meta">
                    <div>{{ __('Lot d\'origine') }} : <b>{{ $batch?->code ?? '—' }}</b></div>
                    <div>{{ __('Bâtiment') }} : <b>{{ $batch?->building?->name ?? '—' }}</b></div>
                    <div>{{ __('Collecte') }} : <b>{{ $eggProduction->total_eggs_collected }} {{ __('œufs') }}</b></div>
                </div>
            </div>
        </div>

        @if($eggProduction->is_graded)
        <div class="grades">
            @foreach(['xl' => 'XL', 'l' => 'L', 'm' => 'M', 's' => 'S'] as $key => $lbl)
            <div class="grade">
                <div class="g">{{ $lbl }}</div>
                <div class="n">{{ rtrim(rtrim(number_format((float) $eggProduction->{"grade_$key"}, 1, '.', ''), '0'), '.') ?: '0' }}</div>
            </div>
            @endforeach
        </div>
        @endif

        <div class="scan">{{ __('Scanner pour la traçabilité du lot') }}</div>
    </div>

    <div class="actions">
        <button onclick="window.print()">{{ __('Imprimer l\'étiquette') }}</button>
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
