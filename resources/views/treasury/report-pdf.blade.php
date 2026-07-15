<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Flux de trésorerie {{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</title>
    @php
        $currency = setting('general.currency', 'GNF');
        $catLabels = [
            'vente' => 'Encaissements ventes', 'remboursement' => 'Remboursements clients',
            'depense' => 'Dépenses', 'achat' => 'Règlements fournisseurs', 'avoir_fournisseur' => 'Avoirs fournisseurs',
            'transfert' => 'Transferts', 'cloture_caisse' => 'Clôtures de caisse', 'manuel' => 'Mouvements manuels',
        ];
        $net = $totalIn - $totalOut;
        $accountName = $accountId ? optional($accounts->firstWhere('id', (int) $accountId))->name : null;
    @endphp
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1e293b; font-size: 11px; margin: 0; }
        .head { border-bottom: 3px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px; }
        .head h1 { font-size: 18px; font-weight: 900; text-transform: uppercase; margin: 0; }
        .head p { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin: 4px 0 0; }
        .totals { width: 100%; margin-bottom: 18px; border-collapse: collapse; }
        .totals td { padding: 10px; text-align: center; border: 1px solid #e2e8f0; }
        .totals .lbl { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; }
        .totals .val { font-size: 16px; font-weight: 900; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.grid th { background: #0f172a; color: #fff; padding: 7px 10px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; text-align: left; }
        table.grid th.r, table.grid td.r { text-align: right; }
        table.grid td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; }
        h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 2px; color: #475569; margin: 14px 0 6px; }
        .in { color: #15803d; } .out { color: #b91c1c; }
        .foot { margin-top: 24px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ setting('general.company_name', 'AviSmart') }} — {{ __('Flux de trésorerie') }}</h1>
        <p>{{ __('Période') }} : {{ $from->format('d/m/Y') }} → {{ $to->format('d/m/Y') }}@if($accountName) · {{ __('Compte') }} : {{ $accountName }}@endif</p>
    </div>

    <table class="totals">
        <tr>
            <td><div class="lbl">{{ __('Entrées') }}</div><div class="val in">{{ number_format($totalIn, 0, ',', ' ') }} {{ $currency }}</div></td>
            <td><div class="lbl">{{ __('Sorties') }}</div><div class="val out">{{ number_format($totalOut, 0, ',', ' ') }} {{ $currency }}</div></td>
            <td><div class="lbl">{{ __('Flux net') }}</div><div class="val">{{ number_format($net, 0, ',', ' ') }} {{ $currency }}</div></td>
        </tr>
    </table>

    <h3>{{ __('Par catégorie') }}</h3>
    <table class="grid">
        <thead><tr><th>{{ __('Catégorie') }}</th><th class="r">{{ __('Entrées') }}</th><th class="r">{{ __('Sorties') }}</th><th class="r">{{ __('Net') }}</th></tr></thead>
        <tbody>
            @forelse($byCategory as $cat => $v)
            <tr>
                <td>{{ $catLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)) }}</td>
                <td class="r in">{{ $v['in'] ? number_format($v['in'], 0, ',', ' ') : '—' }}</td>
                <td class="r out">{{ $v['out'] ? number_format($v['out'], 0, ',', ' ') : '—' }}</td>
                <td class="r">{{ number_format($v['in'] - $v['out'], 0, ',', ' ') }}</td>
            </tr>
            @empty
            <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:16px;">{{ __('Aucun mouvement sur la période.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(! $accountId && $perAccount->isNotEmpty())
    <h3>{{ __('Par compte') }}</h3>
    <table class="grid">
        <thead><tr><th>{{ __('Compte') }}</th><th class="r">{{ __('Entrées') }}</th><th class="r">{{ __('Sorties') }}</th><th class="r">{{ __('Net') }}</th><th class="r">{{ __('Solde actuel') }}</th></tr></thead>
        <tbody>
            @foreach($perAccount as $row)
            <tr>
                <td>{{ $row['account']->name }} <span style="color:#94a3b8;">({{ $row['account']->type_label }})</span></td>
                <td class="r in">{{ number_format($row['in'], 0, ',', ' ') }}</td>
                <td class="r out">{{ number_format($row['out'], 0, ',', ' ') }}</td>
                <td class="r">{{ number_format($row['in'] - $row['out'], 0, ',', ' ') }}</td>
                <td class="r">{{ number_format((float) $row['account']->current_balance, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="foot">{{ __('Édité le') }} {{ now()->format('d/m/Y H:i') }} — {{ setting('general.company_name', 'AviSmart') }}</div>
</body>
</html>
