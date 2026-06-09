<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $type === 'bon' ? 'Bon de Paie' : 'Fiche de Paie' }} — {{ $payslip->employee->first_name }} {{ $payslip->employee->last_name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1e293b; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1e293b; padding-bottom: 15px; margin-bottom: 20px; }
        .company { font-size: 18px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px; }
        .company small { display: block; font-size: 9px; font-weight: 600; color: #64748b; letter-spacing: 3px; margin-top: 4px; }
        .doc-type { text-align: right; }
        .doc-type h2 { font-size: 14px; font-weight: 900; text-transform: uppercase; color: {{ $type === 'bon' ? '#ea580c' : '#059669' }}; }
        .doc-type p { font-size: 9px; color: #94a3b8; margin-top: 4px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
        .info-box label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; display: block; margin-bottom: 4px; }
        .info-box span { font-size: 12px; font-weight: 700; color: #1e293b; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #1e293b; color: white; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; padding: 8px 12px; text-align: left; }
        td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-size: 11px; }
        .amount { text-align: right; font-weight: 700; }
        .prime { color: #059669; }
        .deduction { color: #dc2626; }
        .total-row { background: #f8fafc; font-weight: 900; font-size: 12px; }
        .total-row.net { background: #1e293b; color: white; font-size: 14px; }
        .footer { margin-top: 30px; display: flex; justify-content: space-between; }
        .signature-box { width: 45%; text-align: center; padding-top: 40px; border-top: 1px dashed #cbd5e1; }
        .signature-box p { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; font-weight: 900; color: rgba(0,0,0,0.03); text-transform: uppercase; pointer-events: none; z-index: -1; }
        .stamp { display: inline-block; padding: 6px 16px; border: 2px solid; border-radius: 4px; font-size: 10px; font-weight: 900; text-transform: uppercase; transform: rotate(-5deg); margin-top: 10px; }
        .stamp.paid { border-color: #059669; color: #059669; }
        .stamp.pending { border-color: #ea580c; color: #ea580c; }
        @media print { body { padding: 15px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="watermark">{{ setting('general.company_name', 'AviSmart') }}</div>

    {{-- BOUTON IMPRIMER --}}
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="background: #1e293b; color: white; padding: 10px 25px; border: none; border-radius: 8px; font-weight: 700; font-size: 11px; cursor: pointer; text-transform: uppercase; letter-spacing: 2px;">
            🖨️ Imprimer
        </button>
        <a href="{{ url()->previous() }}" style="margin-left: 10px; color: #64748b; text-decoration: none; font-size: 11px; font-weight: 700;">← Retour</a>
    </div>

    {{-- EN-TÊTE --}}
    <div class="header">
        <div>
            <div class="company">
                {{ setting('general.company_name', 'AviSmart') }}
                <small>{{ setting('general.company_address', 'République de Guinée') }}</small>
                <small>Tél : {{ setting('general.company_phone', '') }}</small>
            </div>
        </div>
        <div class="doc-type">
            <h2>{{ $type === 'bon' ? '📋 Bon de Paie' : '✅ Fiche de Paie' }}</h2>
            <p>{{ $payslip->period->label }} — N° {{ str_pad($payslip->id, 5, '0', STR_PAD_LEFT) }}</p>
            <p>Édité le {{ now()->format('d/m/Y à H:i') }}</p>
            @if($payslip->payment_status === 'paye')
                <div class="stamp paid">PAYÉ le {{ $payslip->paid_at?->format('d/m/Y') }}</div>
            @else
                <div class="stamp pending">À PAYER</div>
            @endif
        </div>
    </div>

    {{-- INFOS EMPLOYÉ --}}
    <div class="info-grid">
        <div class="info-box">
            <label>Employé</label>
            <span>{{ $payslip->employee->first_name }} {{ $payslip->employee->last_name }}</span>
        </div>
        <div class="info-box">
            <label>Matricule</label>
            <span>{{ $payslip->employee->employee_id }}</span>
        </div>
        <div class="info-box">
            <label>Poste / Département</label>
            <span>{{ $payslip->employee->job_title }} — {{ $payslip->employee->department ?? 'Général' }}</span>
        </div>
        <div class="info-box">
            <label>Période</label>
            <span>{{ $payslip->period->start_date->format('d/m/Y') }} → {{ $payslip->period->end_date->format('d/m/Y') }}</span>
        </div>
    </div>

    {{-- PRÉSENCE --}}
    <div class="info-grid">
        <div class="info-box">
            <label>Jours travaillés</label>
            <span>{{ $payslip->days_worked }} jours</span>
        </div>
        <div class="info-box">
            <label>Absences / Congés</label>
            <span>{{ $payslip->days_absent }} abs. + {{ $payslip->days_leave }} congés</span>
        </div>
    </div>

    {{-- DÉTAIL PAIE --}}
    <table>
        <thead>
            <tr>
                <th style="width: 60%;">Désignation</th>
                <th style="width: 20%;">Type</th>
                <th style="width: 20%; text-align: right;">Montant ({{ setting('general.currency', 'GNF') }})</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Salaire de base</strong></td>
                <td>—</td>
                <td class="amount">{{ number_format($payslip->base_salary, 0, ',', '.') }}</td>
            </tr>

            @foreach($payslip->lines->where('type', 'prime') as $line)
            <tr>
                <td>{{ $line->label }}</td>
                <td><span class="prime">+ Prime</span></td>
                <td class="amount prime">+{{ number_format($line->amount, 0, ',', '.') }}</td>
            </tr>
            @endforeach

            @foreach($payslip->lines->where('type', 'deduction') as $line)
            <tr>
                <td>{{ $line->label }}</td>
                <td><span class="deduction">− Déduction</span></td>
                <td class="amount deduction">−{{ number_format($line->amount, 0, ',', '.') }}</td>
            </tr>
            @endforeach

            <tr class="total-row">
                <td colspan="2"><strong>Total Primes</strong></td>
                <td class="amount prime">+{{ number_format($payslip->total_primes, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="2"><strong>Total Déductions</strong></td>
                <td class="amount deduction">−{{ number_format($payslip->total_deductions, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row net">
                <td colspan="2"><strong>NET À PAYER</strong></td>
                <td class="amount">{{ number_format($payslip->net_salary, 0, ',', '.') }} {{ setting('general.currency', 'GNF') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- MODE DE PAIEMENT --}}
    @if($payslip->payment_status === 'paye')
    <div class="info-grid">
        <div class="info-box">
            <label>Mode de paiement</label>
            <span>{{ match($payslip->payment_method) { 'especes' => '💵 Espèces', 'orange_money' => '📱 Orange Money', 'virement' => '🏦 Virement', default => $payslip->payment_method } }}</span>
        </div>
        <div class="info-box">
            <label>Référence</label>
            <span>{{ $payslip->payment_reference ?? '—' }}</span>
        </div>
    </div>
    @endif

    {{-- SIGNATURES --}}
    <div class="footer">
        <div class="signature-box">
            <p>L'Employeur</p>
            <p style="margin-top: 5px; font-size: 8px; color: #94a3b8;">Cachet et signature</p>
        </div>
        <div class="signature-box">
            <p>L'Employé</p>
            <p style="margin-top: 5px; font-size: 8px; color: #94a3b8;">Lu et approuvé</p>
        </div>
    </div>

    <div style="margin-top: 30px; text-align: center; font-size: 8px; color: #cbd5e1; border-top: 1px solid #f1f5f9; padding-top: 10px;">
        {{ setting('general.company_name', 'AviSmart') }} — Système ERP — Document généré automatiquement
    </div>
</body>
</html>
