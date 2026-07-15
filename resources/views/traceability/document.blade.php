<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Traçabilité — {{ $code }}</title>
    @php
        $themes = [
            'lime'   => '#3f6212',
            'green'  => '#166534',
            'indigo' => '#3730a3',
            'slate'  => '#0f172a',
        ];
        $accentColor = $themes[$accent ?? 'slate'] ?? '#0f172a';
    @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #0f172a; padding: 16px; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 40px rgba(15,23,42,.08); }
        .head { background: {{ $accentColor }}; color: #fff; padding: 28px 24px; }
        .head .farm { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; opacity: .75; }
        .head .title { font-size: 13px; font-weight: 700; opacity: .9; margin-top: 4px; }
        .head .code { font-size: 28px; font-weight: 900; font-style: italic; margin-top: 6px; }
        .badge { display: inline-block; margin-top: 12px; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; background: rgba(255,255,255,.2); color: #fff; }
        .verified { text-align:center; padding: 16px 24px 0; color:#16a34a; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:1px;}
        .rows { padding: 8px 24px 24px; }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 0; border-bottom: 1px solid #f1f5f9; }
        .row:last-child { border-bottom: 0; }
        .row .k { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; flex-shrink: 0; }
        .row .v { font-size: 15px; font-weight: 800; text-align: right; }
        .foot { padding: 18px 24px; background: #f8fafc; text-align: center; font-size: 11px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <div class="farm">{{ $farm ?? 'Ferme' }}</div>
            <div class="title">{{ $title }}</div>
            <div class="code">{{ $code }}</div>
            @if(!empty($status))
            <span class="badge">{{ $status }}</span>
            @endif
        </div>

        <div class="verified"><i>✓ Origine certifiée</i></div>

        <div class="rows">
            @foreach($rows as $row)
            <div class="row">
                <span class="k">{{ $row[0] }}</span>
                <span class="v">{{ $row[1] }}</span>
            </div>
            @endforeach
        </div>

        <div class="foot">
            Fiche de traçabilité générée par {{ $farm ?? 'AviSmart' }} ERP<br>
            Consultée le {{ now()->format('d/m/Y à H:i') }}
        </div>
    </div>
</body>
</html>
