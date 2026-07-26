<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        h2 { font-size: 10px; margin: 20px 0 8px; text-transform: uppercase; letter-spacing: 2px; color: #64748b; }
        .sub { color: #4f46e5; font-size: 9px; margin: 4px 0 16px; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; text-align: left; padding: 7px 8px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
        th.num, td.num { text-align: right; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        td.label { font-weight: bold; }
        td.detail { color: #94a3b8; font-size: 9px; }
        .ok { color: #16a34a; font-weight: bold; }
        .warn { color: #d97706; font-weight: bold; }
        .bad { color: #dc2626; font-weight: bold; }
        .neutral { color: #475569; font-weight: bold; }
        .na { color: #cbd5e1; font-style: italic; }
        .foot { color: #94a3b8; font-size: 8px; margin-top: 18px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>Suivi hebdomadaire</h1>
    <p class="sub">
        {{ $sheet['employee']->first_name }} {{ $sheet['employee']->last_name }}
        @if($sheet['employee']->job_title) — {{ $sheet['employee']->job_title }} @endif
        · Semaine {{ $sheet['from']->isoWeek() }} ({{ $sheet['from']->format('d/m/Y') }} → {{ $sheet['to']->format('d/m/Y') }})
    </p>

    <h2>Indicateurs</h2>
    <table>
        <thead>
            <tr>
                <th>Indicateur</th>
                <th class="num">Valeur</th>
                <th class="num">Cible</th>
                <th>Détail</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sheet['indicators'] as $ind)
                <tr>
                    <td class="label">{{ $ind['label'] }}</td>
                    <td class="num">
                        @if($ind['value'] === null)
                            {{-- Donnée absente, pas un résultat nul. --}}
                            <span class="na">non mesurable</span>
                        @else
                            <span class="{{ $ind['tone'] }}">
                                {{ number_format($ind['value'], $ind['unit'] === '%' ? 1 : 2, ',', ' ') }}{{ $ind['unit'] }}
                            </span>
                        @endif
                    </td>
                    <td class="num">{{ $ind['target'] }}</td>
                    <td class="detail">{{ $ind['detail'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(! empty($sheet['batches']))
        <h2>Lots sous responsabilité</h2>
        <table>
            <thead>
                <tr>
                    <th>Lot</th>
                    <th>Bâtiment</th>
                    <th class="num">Âge</th>
                    <th class="num">Sujets</th>
                    <th class="num">Mortalité</th>
                    <th class="num">FCR</th>
                    <th class="num">Écart aliment</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sheet['batches'] as $b)
                    <tr>
                        <td class="label">{{ $b['code'] }}</td>
                        <td class="detail">{{ $b['building'] ?? '—' }}</td>
                        <td class="num">J{{ $b['age_days'] }}</td>
                        <td class="num">{{ number_format($b['current'], 0, ',', ' ') }}</td>
                        <td class="num">{{ number_format($b['mortality_rate'], 2, ',', ' ') }} %</td>
                        <td class="num">{{ $b['fcr'] !== null ? number_format($b['fcr'], 2, ',', ' ') : '—' }}</td>
                        <td class="num">
                            @if($b['feed_gap_percent'] === null)
                                —
                            @else
                                {{ $b['feed_gap_percent'] > 0 ? '+' : '' }}{{ number_format($b['feed_gap_percent'], 1, ',', ' ') }} %
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(! empty($sheet['cycles']))
        <h2>Cultures et avancement d'itinéraire</h2>
        <table>
            <thead>
                <tr>
                    <th>Culture</th>
                    <th>Parcelle</th>
                    <th class="num">Jours après semis</th>
                    <th class="num">Étapes faites</th>
                    <th class="num">En retard</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sheet['cycles'] as $c)
                    <tr>
                        <td class="label">{{ $c['crop_name'] }} <span class="detail">{{ $c['code'] }}</span></td>
                        <td class="detail">{{ $c['plot'] ?? '—' }}</td>
                        <td class="num">{{ $c['days_after_planting'] !== null ? 'J+' . $c['days_after_planting'] : '—' }}</td>
                        <td class="num">{{ $c['steps_done'] }} / {{ $c['steps_total'] }}</td>
                        <td class="num {{ $c['steps_late'] > 0 ? 'bad' : '' }}">{{ $c['steps_late'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($sheet['incidents'] > 0)
        <p class="warn" style="margin-top: 16px;">
            {{ $sheet['incidents'] }} incident(s) sanitaire(s) déclaré(s) cette semaine.
        </p>
    @endif

    <p class="foot">
        Édité le {{ now()->format('d/m/Y à H:i') }} — La ponctualité est mesurée sur la date déclarée de l'acte,
        non sur son arrivée au serveur (les sites sans couverture réseau ne sont pas pénalisés).
    </p>
</body>
</html>
