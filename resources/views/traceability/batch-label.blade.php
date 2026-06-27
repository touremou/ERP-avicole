<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Étiquette — Lot {{ $batch->code }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #e2e8f0; padding: 20px; }
        .label { width: 380px; margin: 0 auto; background: #fff; border: 2px solid #0f172a; border-radius: 12px; padding: 18px; display: flex; gap: 16px; align-items: center; }
        .qr { width: 140px; height: 140px; flex-shrink: 0; }
        .qr img { width: 100%; height: 100%; display: block; }
        .info { flex: 1; min-width: 0; }
        .farm { font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; font-weight: 700; }
        .code { font-size: 24px; font-weight: 900; font-style: italic; line-height: 1.1; margin: 2px 0 8px; }
        .meta { font-size: 11px; color: #334155; line-height: 1.6; }
        .meta b { font-weight: 800; }
        .scan { margin-top: 10px; font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        .actions { max-width: 380px; margin: 18px auto 0; text-align: center; }
        .actions button { background: #0f172a; color: #fff; border: 0; padding: 12px 28px; border-radius: 10px; font-weight: 800; font-size: 13px; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .label { border-color: #000; }
            .actions { display: none; }
            @page { margin: 8mm; }
        }
    </style>
</head>
<body>
    <div class="label">
        <div class="qr"><img src="{{ $qr }}" alt="QR {{ $batch->code }}"></div>
        <div class="info">
            <div class="farm">{{ $batch->farm?->name ?? __('Traçabilité') }}</div>
            <div class="code">{{ $batch->code }}</div>
            <div class="meta">
                <div><b>{{ $batch->species?->name_fr ?? '—' }}</b> · {{ $batch->productionType?->name_fr ?? '—' }}</div>
                <div>{{ __('Bâtiment') }} : <b>{{ $batch->building?->name ?? '—' }}</b></div>
                <div>{{ __('Arrivée') }} : <b>{{ $batch->arrival_date?->format('d/m/Y') ?? '—' }}</b></div>
            </div>
            <div class="scan">{{ __('Scanner pour la traçabilité') }}</div>
        </div>
    </div>

    <div class="actions">
        <button onclick="window.print()">{{ __('Imprimer l\'étiquette') }}</button>
    </div>

    <script>
        // Ouverture directe en dialogue d'impression (lien depuis la fiche lot).
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
