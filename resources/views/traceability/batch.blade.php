<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Traçabilité — Lot {{ $batch->code }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #0f172a; padding: 16px; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 40px rgba(15,23,42,.08); }
        .head { background: #0f172a; color: #fff; padding: 28px 24px; }
        .head .farm { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; opacity: .7; }
        .head .code { font-size: 30px; font-weight: 900; font-style: italic; margin-top: 6px; }
        .badge { display: inline-block; margin-top: 12px; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.off { background: #e2e8f0; color: #475569; }
        .rows { padding: 8px 24px 24px; }
        .row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid #f1f5f9; }
        .row:last-child { border-bottom: 0; }
        .row .k { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; }
        .row .v { font-size: 15px; font-weight: 800; text-align: right; }
        .foot { padding: 18px 24px; background: #f8fafc; text-align: center; font-size: 11px; color: #94a3b8; }
        .verified { text-align:center; padding: 16px 24px 0; color:#16a34a; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:1px;}
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <div class="farm">{{ $batch->farm?->name ?? 'Ferme' }}</div>
            <div class="code">{{ $batch->code }}</div>
            @php $active = in_array($batch->status, ['Actif', 'En cours']); @endphp
            <span class="badge {{ $active ? 'ok' : 'off' }}">{{ $batch->status }}</span>
        </div>

        <div class="verified"><i>✓ Origine certifiée</i></div>

        <div class="rows">
            <div class="row">
                <span class="k">Espèce</span>
                <span class="v">{{ $batch->species?->name_fr ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="k">Type de production</span>
                <span class="v">{{ $batch->productionType?->name_fr ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="k">Bâtiment</span>
                <span class="v">{{ $batch->building?->name ?? '—' }}</span>
            </div>
            <div class="row">
                <span class="k">Date d'arrivée</span>
                <span class="v">{{ $batch->arrival_date?->format('d/m/Y') ?? '—' }}</span>
            </div>
            @if($batch->expected_end_date)
            <div class="row">
                <span class="k">Fin prévue</span>
                <span class="v">{{ $batch->expected_end_date->format('d/m/Y') }}</span>
            </div>
            @endif
            @if($batch->provider)
            <div class="row">
                <span class="k">Fournisseur d'origine</span>
                <span class="v">{{ $batch->provider->name ?? '—' }}</span>
            </div>
            @endif
            @if($batch->farm?->city || $batch->farm?->region)
            <div class="row">
                <span class="k">Localisation</span>
                <span class="v">{{ collect([$batch->farm->city, $batch->farm->region])->filter()->join(', ') }}</span>
            </div>
            @endif
        </div>

        <div class="foot">
            Fiche de traçabilité générée par {{ $batch->farm?->name ?? 'AviSmart' }} ERP<br>
            Consultée le {{ now()->format('d/m/Y à H:i') }}
        </div>
    </div>
</body>
</html>
