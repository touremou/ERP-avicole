<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .sub { color: #7c3aed; font-size: 9px; margin: 4px 0 16px; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 7px 8px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
        th.num, td.num { text-align: center; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        td.name { font-weight: bold; }
        td.poste { color: #94a3b8; font-size: 9px; }
        .rate-ok { color: #16a34a; font-weight: bold; }
        .rate-warn { color: #d97706; font-weight: bold; }
        .rate-bad { color: #dc2626; font-weight: bold; }
        .foot { color: #94a3b8; font-size: 8px; margin-top: 18px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>Rapport de présence</h1>
    <p class="sub">{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</p>

    <table>
        <thead>
            <tr>
                <th>Employé</th>
                <th>Poste</th>
                <th class="num">Présent</th>
                <th class="num">Retard</th>
                <th class="num">Absent</th>
                <th class="num">Congé</th>
                <th class="num">Pointés</th>
                <th class="num">Taux</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
            <tr>
                <td class="name">{{ $r['employee']->first_name }} {{ $r['employee']->last_name }}</td>
                <td class="poste">{{ $r['employee']->job_title ?? '—' }}</td>
                <td class="num">{{ $r['counts']['present'] }}</td>
                <td class="num">{{ $r['counts']['retard'] }}</td>
                <td class="num">{{ $r['counts']['absent'] }}</td>
                <td class="num">{{ $r['counts']['conge'] }}</td>
                <td class="num">{{ $r['total'] }}</td>
                <td class="num @if($r['total'] === 0){{ '' }}@elseif($r['presence_rate'] >= 90)rate-ok @elseif($r['presence_rate'] >= 70)rate-warn @else rate-bad @endif">
                    {{ $r['total'] > 0 ? $r['presence_rate'] . ' %' : '—' }}
                </td>
            </tr>
            @empty
            <tr><td colspan="8">Aucun employé actif.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="foot">Taux de présence = (présents + retards) / jours pointés · Le congé est une absence justifiée · Généré le {{ now()->format('d/m/Y à H:i') }}</p>
</body>
</html>
