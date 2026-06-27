<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10px; color: #1e293b; margin: 0; }
        h1 { font-size: 17px; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .sub { color: #ea580c; font-size: 9px; margin: 4px 0 14px; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 6px 7px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
        th.num, td.num { text-align: right; }
        td { padding: 6px 7px; border-bottom: 1px solid #e2e8f0; }
        .loss { color: #dc2626; }
        .gain { color: #059669; }
        tr.total td { font-weight: bold; border-top: 2px solid #1e293b; border-bottom: none; background: #f1f5f9; }
        .foot { color: #94a3b8; font-size: 8px; margin-top: 16px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>Journal de démarque</h1>
    <p class="sub">{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} — Écarts d'inventaire valorisés</p>

    <table>
        <thead>
            <tr>
                <th>Réf.</th>
                <th>Date</th>
                <th>Article</th>
                <th>Motif</th>
                <th>Type</th>
                <th class="num">Avant</th>
                <th class="num">Après</th>
                <th class="num">Écart</th>
                <th class="num">CMP</th>
                <th class="num">Valeur</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
            <tr>
                <td>{{ $r->reference }}</td>
                <td>{{ $r->adjustment_date->format('d/m/Y') }}</td>
                <td>{{ $r->stock?->item_name ?? '—' }}</td>
                <td>{{ $r->reason_label }}</td>
                <td class="{{ $r->is_loss ? 'loss' : 'gain' }}">{{ $r->type }}</td>
                <td class="num">{{ number_format($r->quantity_before, 2, ',', ' ') }}</td>
                <td class="num">{{ number_format($r->quantity_after, 2, ',', ' ') }}</td>
                <td class="num {{ $r->is_loss ? 'loss' : 'gain' }}">{{ $r->delta > 0 ? '+' : '' }}{{ number_format($r->delta, 2, ',', ' ') }}</td>
                <td class="num">{{ number_format($r->unit_cost, 0, ',', ' ') }}</td>
                <td class="num {{ $r->is_loss ? 'loss' : 'gain' }}">{{ $r->is_loss ? '−' : '+' }}{{ number_format($r->value_impact, 0, ',', ' ') }}</td>
            </tr>
            @empty
            <tr><td colspan="10">Aucun ajustement sur la période.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="9">DÉMARQUE (PERTES) / GAINS</td>
                <td class="num"><span class="loss">−{{ number_format($lossValue, 0, ',', ' ') }}</span> / <span class="gain">+{{ number_format($gainValue, 0, ',', ' ') }}</span> {{ currency() }}</td>
            </tr>
        </tbody>
    </table>

    <p class="foot">Généré le {{ now()->format('d/m/Y à H:i') }} — {{ setting('general.company_name', config('app.name')) }}</p>
</body>
</html>
