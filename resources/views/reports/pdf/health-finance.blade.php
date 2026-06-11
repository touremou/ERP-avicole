<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Analyse Financière Santé</title>
    <style>
        @page { margin: 25px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 4px 0; text-transform: uppercase; }
        .subtitle { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
        .kpi-row { width: 100%; margin-bottom: 16px; }
        .kpi-row td { border: none; padding: 0 6px 0 0; }
        .kpi-box { padding: 12px 16px; border-radius: 8px; color: #fff; }
        .kpi-box.dark { background: #172554; }
        .kpi-box.green { background: #047857; }
        .kpi-box .label { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7; }
        .kpi-box .value { font-size: 22px; font-weight: bold; }
        h2.section { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin: 18px 0 8px 0; color: #1e293b; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background: #f1f5f9; text-transform: uppercase; font-size: 8px; letter-spacing: 1px; color: #64748b; }
        table.data td.amount { text-align: right; }
        .surv-good { color: #047857; font-weight: bold; }
        .surv-bad { color: #be123c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 20px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <h1>Analyse Financière Santé</h1>
    <div class="subtitle">
        Performance économique prophylactique ·
        Période : {{ ['all' => 'Historique global', 'year' => 'Exercice ' . date('Y'), 'month' => 'Mois en cours'][$period] ?? $period }} ·
        Statut : {{ ['all' => 'Tous les lots', 'actif' => 'En cours', 'clos' => 'Archives'][$statusFilter] ?? $statusFilter }} ·
        Généré le {{ now()->format('d/m/Y H:i') }}
    </div>

    <table class="kpi-row">
        <tr>
            <td style="width: 33%;">
                <div class="kpi-box dark">
                    <div class="label">Dépense sanitaire totale</div>
                    <div class="value">{{ number_format($totalGlobalCost, 0, ',', ' ') }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box dark">
                    <div class="label">Coût moyen / tête</div>
                    <div class="value">{{ number_format($averageCostPerHead, 0, ',', ' ') }}</div>
                </div>
            </td>
            <td style="width: 33%;">
                <div class="kpi-box green">
                    <div class="label">Lot d'excellence</div>
                    <div class="value">{{ $bestBatch->code ?? 'N/A' }} — {{ number_format($bestBatchCost, 0) }}/tête</div>
                </div>
            </td>
        </tr>
    </table>

    <h2 class="section">Structure des coûts</h2>
    <table class="data">
        <thead>
            <tr><th>Type</th><th class="amount" style="text-align:right;">Montant</th><th class="amount" style="text-align:right;">% du total</th></tr>
        </thead>
        <tbody>
            @foreach(['Vaccin', 'Traitement', 'Vitamine', 'Désinfection'] as $type)
            @php
                $amount = $typeBreakdown[$type] ?? 0;
                $percent = $totalGlobalCost > 0 ? ($amount / $totalGlobalCost) * 100 : 0;
            @endphp
            <tr>
                <td>{{ $type }}</td>
                <td class="amount">{{ number_format($amount, 0, ',', ' ') }}</td>
                <td class="amount">{{ number_format($percent, 1) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section">Registre analytique des lots</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Lot</th>
                <th>Bâtiment</th>
                <th>Statut</th>
                <th class="amount" style="text-align:right;">Effectif initial</th>
                <th class="amount" style="text-align:right;">Total investi</th>
                <th class="amount" style="text-align:right;">Coût / tête</th>
            </tr>
        </thead>
        <tbody>
            @forelse($batches as $batch)
            @php
                $totalBatchCost = $batch->healthChecks->sum('cost');
                $ratio = $batch->initial_quantity > 0 ? $totalBatchCost / $batch->initial_quantity : 0;
            @endphp
            <tr>
                <td>{{ $batch->code }}</td>
                <td>{{ $batch->building->name ?? 'N/A' }}</td>
                <td>{{ $batch->status }}</td>
                <td class="amount">{{ number_format($batch->initial_quantity, 0, ',', ' ') }}</td>
                <td class="amount">{{ number_format($totalBatchCost, 0, ',', ' ') }}</td>
                <td class="amount">
                    <span class="{{ $ratio > $averageCostPerHead ? 'surv-bad' : 'surv-good' }}">{{ number_format($ratio, 0) }}</span>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="muted">Aucun lot trouvé.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">AviSmart ERP — Rapport généré automatiquement</div>
</body>
</html>
