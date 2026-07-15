<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .sub { color: #e11d48; font-size: 9px; margin: 4px 0 16px; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        .meta { width: 100%; margin-bottom: 14px; font-size: 10px; }
        .meta td { padding: 2px 0; }
        .meta .lbl { color: #94a3b8; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; }
        table.led { width: 100%; border-collapse: collapse; }
        table.led th { background: #1e293b; color: #fff; text-align: left; padding: 7px 8px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
        table.led th.num, table.led td.num { text-align: right; }
        table.led td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        tr.total td { font-weight: bold; border-top: 2px solid #1e293b; border-bottom: none; background: #f1f5f9; }
        .refund { color: #dc2626; }
        .foot { color: #94a3b8; font-size: 8px; margin-top: 18px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>Relevé fournisseur</h1>
    <p class="sub">{{ $provider->name }} — {{ $provider->provider_id }}</p>

    <table class="meta">
        <tr>
            <td class="lbl">Téléphone</td><td>{{ $provider->phone ?? '—' }}</td>
            <td class="lbl">Total acheté</td><td>{{ number_format($statement['total_debit'], 0, ',', ' ') }} {{ currency() }}</td>
        </tr>
        <tr>
            <td class="lbl">Type</td><td>{{ $provider->type ?? '—' }}</td>
            <td class="lbl">Dette en cours</td><td><strong>{{ number_format($statement['balance'], 0, ',', ' ') }} {{ currency() }}</strong></td>
        </tr>
    </table>

    <table class="led">
        <thead>
            <tr>
                <th>Date</th>
                <th>Libellé</th>
                <th class="num">Débit</th>
                <th class="num">Crédit</th>
                <th class="num">Solde</th>
            </tr>
        </thead>
        <tbody>
            @forelse($statement['rows'] as $r)
            <tr>
                <td>{{ $r['date']->format('d/m/Y') }}</td>
                <td>{{ $r['label'] }}</td>
                <td class="num">{{ $r['debit'] ? number_format($r['debit'], 0, ',', ' ') : '' }}</td>
                <td class="num {{ $r['credit'] < 0 ? 'refund' : '' }}">{{ $r['credit'] ? number_format($r['credit'], 0, ',', ' ') : '' }}</td>
                <td class="num">{{ number_format($r['balance'], 0, ',', ' ') }}</td>
            </tr>
            @empty
            <tr><td colspan="5">Aucun mouvement.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">DETTE EN COURS</td>
                <td class="num">{{ number_format($statement['total_debit'], 0, ',', ' ') }}</td>
                <td class="num">{{ number_format($statement['total_credit'], 0, ',', ' ') }}</td>
                <td class="num">{{ number_format($statement['balance'], 0, ',', ' ') }} {{ currency() }}</td>
            </tr>
        </tbody>
    </table>

    <p class="foot">Généré le {{ now()->format('d/m/Y à H:i') }} — {{ setting('general.company_name', config('app.name')) }}</p>
</body>
</html>
