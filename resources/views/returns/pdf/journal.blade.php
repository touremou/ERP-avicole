<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .sub { color: #ea580c; font-size: 9px; margin: 4px 0 16px; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 7px 8px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
        th.num, td.num { text-align: right; }
        th.c, td.c { text-align: center; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        tr.total td { font-weight: bold; border-top: 2px solid #1e293b; border-bottom: none; }
        .foot { color: #94a3b8; font-size: 8px; margin-top: 18px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>Journal des avoirs</h1>
    <p class="sub">{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} — Retours & remboursements</p>

    <table>
        <thead>
            <tr>
                <th>Avoir</th>
                <th>Date</th>
                <th>Vente</th>
                <th>Client</th>
                <th class="c">Art.</th>
                <th class="num">Remboursé</th>
                <th>Mode</th>
            </tr>
        </thead>
        <tbody>
            @forelse($returns as $r)
            <tr>
                <td>{{ $r->reference }}</td>
                <td>{{ $r->return_date->format('d/m/Y') }}</td>
                <td>{{ $r->sale?->reference ?? '—' }}</td>
                <td>{{ $r->sale?->client?->name ?? '—' }}</td>
                <td class="c">{{ $r->items_count }}</td>
                <td class="num">{{ number_format($r->total_refund, 0, ',', ' ') }}</td>
                <td>{{ ['especes'=>'Espèces','orange_money'=>'OM/MoMo','virement'=>'Virement','cheque'=>'Chèque'][$r->refund_method] ?? $r->refund_method }}</td>
            </tr>
            @empty
            <tr><td colspan="7">Aucun avoir sur cette période.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="5">TOTAL REMBOURSÉ</td>
                <td class="num">{{ number_format($totalRefund, 0, ',', ' ') }} {{ currency() }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <p class="foot">Généré le {{ now()->format('d/m/Y à H:i') }}</p>
</body>
</html>
