<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .sub { color: #6366f1; font-size: 9px; margin: 4px 0 16px; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 7px 8px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
        th.num, td.num { text-align: right; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        tr.over td { background: #fef2f2; }
        tr.total td { font-weight: bold; border-top: 2px solid #1e293b; border-bottom: none; }
        .st-over { color: #dc2626; font-weight: bold; }
        .st-nb { color: #d97706; }
        .st-ok { color: #16a34a; }
        .foot { color: #94a3b8; font-size: 8px; margin-top: 18px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    @php
        $months = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
        $periodLabel = $mode === 'year' ? ('Année ' . $year) : (($months[$month] ?? $month) . ' ' . $year);
    @endphp

    <h1>Suivi budgétaire</h1>
    <p class="sub">{{ $periodLabel }} — Budget vs dépenses validées</p>

    <table>
        <thead>
            <tr>
                <th>Poste</th>
                <th class="num">Budget</th>
                <th class="num">Dépensé</th>
                <th class="num">Reste</th>
                <th class="num">Conso.</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
            <tr class="{{ $r['over'] ? 'over' : '' }}">
                <td>{{ $r['label'] }}</td>
                <td class="num">{{ number_format($r['budget'], 0, ',', ' ') }}</td>
                <td class="num">{{ number_format($r['spent'], 0, ',', ' ') }}</td>
                <td class="num">{{ number_format($r['remaining'], 0, ',', ' ') }}</td>
                <td class="num">{{ $r['budget'] > 0 ? $r['pct'] . ' %' : '—' }}</td>
                <td class="{{ $r['over'] ? 'st-over' : ($r['no_budget'] ? 'st-nb' : 'st-ok') }}">
                    {{ $r['over'] ? 'Dépassement' : ($r['no_budget'] ? 'Non budgété' : 'OK') }}
                </td>
            </tr>
            @endforeach
            <tr class="total">
                <td>TOTAL</td>
                <td class="num">{{ number_format($totals['budget'], 0, ',', ' ') }}</td>
                <td class="num">{{ number_format($totals['spent'], 0, ',', ' ') }}</td>
                <td class="num">{{ number_format($totals['remaining'], 0, ',', ' ') }}</td>
                <td></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <p class="foot">Généré le {{ now()->format('d/m/Y à H:i') }} — AviSmart ERP</p>
</body>
</html>
