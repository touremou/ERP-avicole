<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __("Installation") }} — {{ config('app.name', 'AviSmart') }}</title>
    <style>
        :root {
            --primary: #16a34a;
            --primary-dark: #15803d;
            --bg: #f1f5f9;
            --text: #1e293b;
            --muted: #64748b;
            --danger: #dc2626;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 720px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            padding: 28px 32px;
        }
        .card-header h1 {
            margin: 0 0 4px;
            font-size: 22px;
        }
        .card-header p {
            margin: 0;
            opacity: .9;
            font-size: 14px;
        }
        .steps {
            display: flex;
            justify-content: space-between;
            padding: 16px 32px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted);
        }
        .steps span.active { color: var(--primary-dark); font-weight: 600; }
        .card-body { padding: 32px; }
        h2 { margin-top: 0; font-size: 18px; }
        .field { margin-bottom: 16px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
        input[type=text], input[type=email], input[type=password], input[type=number], select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
        }
        .help { font-size: 12px; color: var(--muted); margin-top: 4px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .btn {
            display: inline-block;
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { background: var(--primary-dark); }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .btn-secondary { background: #e2e8f0; color: var(--text); }
        .btn-secondary:hover { background: #cbd5e1; }
        .actions { margin-top: 24px; display: flex; justify-content: space-between; align-items: center; }
        ul.checklist { list-style: none; padding: 0; margin: 0; }
        ul.checklist li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        ul.checklist li:last-child { border-bottom: none; }
        .badge { font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: 999px; }
        .badge-ok { background: #dcfce7; color: #15803d; }
        .badge-fail { background: #fee2e2; color: #b91c1c; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
        .alert-success { background: #dcfce7; color: #15803d; }
        pre.output {
            background: #0f172a;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            font-size: 12px;
            max-height: 260px;
            overflow: auto;
            white-space: pre-wrap;
        }
        .checkbox-row { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .checkbox-row input { width: auto; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h1>🐔 {{ __("Installation") }} — {{ config('app.name', 'AviSmart') }}</h1>
            <p>{{ __("Assistant de configuration initiale") }}</p>
        </div>
        <div class="steps">
            <span class="{{ $step === 1 ? 'active' : '' }}">1. {{ __("Prérequis") }}</span>
            <span class="{{ $step === 2 ? 'active' : '' }}">2. {{ __("Base de données") }}</span>
            <span class="{{ $step === 3 ? 'active' : '' }}">3. {{ __("Migrations") }}</span>
            <span class="{{ $step === 4 ? 'active' : '' }}">4. {{ __("Administrateur") }}</span>
            <span class="{{ $step === 5 ? 'active' : '' }}">5. {{ __("Terminé") }}</span>
        </div>
        <div class="card-body">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
