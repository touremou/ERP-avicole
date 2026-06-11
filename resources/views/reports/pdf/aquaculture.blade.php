<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport Pisciculture</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpi-row { width: 100%; margin-bottom: 16px; }
        .kpi-row td { border: none; padding: 0 6px 0 0; }
        .kpi-box { padding: 12px 16px; border-radius: 8px; color: #fff; }
        .kpi-box.dark { background: #172554; }
        .kpi-box.ok { background: #047857; }
        .kpi-box.warn { background: #b45309; }
        .kpi-box.crit { background: #be123c; }
        .kpi-box .label { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7; }
        .kpi-box .value { font-size: 22px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        .surv-good { color: #047857; font-weight: bold; }
        .surv-mid { color: #b45309; font-weight: bold; }
        .surv-bad { color: #be123c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .alert-crit { color: #be123c; font-weight: bold; }
        .alert-warn { color: #b45309; font-weight: bold; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>Rapport Pisciculture</h1>
    <div class="subtitle">Qualité de l'eau & survie par bassin · Statut : {{ $statusFilter === 'all' ? 'Tous' : $statusFilter }} · Généré le {{ now()->format('d/m/Y H:i') }}</div>

    <table class="kpi-row">
        <tr>
            <td style="width: 33%;">
                <div class="kpi-box dark">
                    <div class="label">Bassins suivis</div>
                    <div class="value">{{ $batchStats->count() }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box {{ $criticalCount > 0 ? 'crit' : ($totalAlerts > 0 ? 'warn' : 'ok') }}">
                    <div class="label">Alertes qualité d'eau</div>
                    <div class="value">{{ $totalAlerts }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box crit">
                    <div class="label">Alertes critiques</div>
                    <div class="value">{{ $criticalCount }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Bassin</th>
                <th>Espèce</th>
                <th>Âge (j)</th>
                <th>Temp.</th>
                <th>pH</th>
                <th>O₂ (ppm)</th>
                <th>NH₃ (ppm)</th>
                <th>Biomasse</th>
                <th>Survie (cible)</th>
                <th>IC (cible)</th>
                <th>Cycle</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @forelse($batchStats as $stat)
            @php $batch = $stat['batch']; $ext = $stat['last_ext']; @endphp
            <tr>
                <td>{{ $batch->code }}</td>
                <td>{{ $batch->species?->name_fr ?? 'Aquaculture' }}</td>
                <td>{{ $stat['age_days'] }}</td>
                <td>{{ $ext?->water_temp !== null ? number_format($ext->water_temp, 1).'°C' : '—' }}</td>
                <td>{{ $ext?->water_ph !== null ? number_format($ext->water_ph, 2) : '—' }}</td>
                <td>{{ $ext?->water_o2_ppm !== null ? number_format($ext->water_o2_ppm, 1) : '—' }}</td>
                <td>{{ $ext?->water_ammonia_ppm !== null ? number_format($ext->water_ammonia_ppm, 2) : '—' }}</td>
                <td>{{ $ext?->biomass_kg !== null ? number_format($ext->biomass_kg, 1).' kg' : '—' }}</td>
                <td>
                    @php $survival = $ext?->survival_rate; @endphp
                    @if($survival !== null)
                        <span class="{{ $survival >= $stat['survival_target'] ? 'surv-good' : ($survival >= $stat['survival_target'] * 0.8 ? 'surv-mid' : 'surv-bad') }}">{{ number_format($survival, 1) }}%</span> ({{ number_format($stat['survival_target'], 0) }}%)
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>
                    @if($stat['fc_real'] !== null)
                        <span class="{{ $stat['fc_real'] <= $stat['fc_target'] ? 'surv-good' : ($stat['fc_real'] <= $stat['fc_target'] * 1.2 ? 'surv-mid' : 'surv-bad') }}">{{ number_format($stat['fc_real'], 2) }}</span> ({{ number_format($stat['fc_target'], 2) }})
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>
                    @if($stat['cycle_days'])
                        J{{ $stat['age_days'] }}/{{ $stat['cycle_days'] }} — {{ $stat['days_remaining'] > 0 ? 'reste ' . $stat['days_remaining'] . ' j' : 'récolte due' }}
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td>{{ $batch->status }}</td>
            </tr>
            @empty
            <tr><td colspan="12" class="muted">Aucun lot pisciculture trouvé.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Détail des alertes --}}
    @if($totalAlerts > 0)
    <table class="data">
        <thead>
            <tr>
                <th>Bassin</th>
                <th>Niveau</th>
                <th>Message</th>
            </tr>
        </thead>
        <tbody>
            @foreach($batchStats as $stat)
                @foreach($stat['alerts'] as $alert)
                <tr>
                    <td>{{ $stat['batch']->code }}</td>
                    <td class="{{ $alert['level'] === 'critical' ? 'alert-crit' : 'alert-warn' }}">
                        {{ $alert['level'] === 'critical' ? 'Critique' : 'Avertissement' }}
                    </td>
                    <td>{{ $alert['message'] }}</td>
                </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">AviSmart ERP — Rapport généré automatiquement</div>
</body>
</html>
