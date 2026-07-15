<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    @php
        $docLabel = match ($sale->type) {
            'facture'  => __("Facture"),
            'comptant' => __("Reçu"),
            default    => __("Bon de Livraison"),
        };
    @endphp
    <title>{{ $sale->reference }} — {{ $docLabel }}</title>
    <style>
        /* margin:0 sur @page supprime l'en-tête/pied INJECTÉS par le navigateur
           (URL du site, date, n° de page) ; la marge visuelle est reportée en
           padding sur le body. */
        @page { size: A4; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { font-size: 11px; color: #1e293b; line-height: 1.6; padding: 15mm; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; border-bottom: 3px solid #0f172a; padding-bottom: 20px; }
        .logo-area h1 { font-size: 22px; font-weight: 900; text-transform: uppercase; letter-spacing: -1px; }
        .logo-area p { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; color: #64748b; margin-top: 4px; }
        .doc-info { text-align: right; }
        .doc-info .ref { font-size: 18px; font-weight: 900; text-transform: uppercase; }
        .doc-info .type { font-size: 10px; text-transform: uppercase; letter-spacing: 3px; color: #0d9488; font-weight: 800; margin-top: 2px; }
        .doc-info .date { font-size: 9px; color: #94a3b8; margin-top: 8px; }
        .parties { display: flex; justify-content: space-between; margin-bottom: 25px; }
        .party { width: 48%; padding: 15px; border-radius: 8px; }
        .party.vendor { background: #f8fafc; border: 1px solid #e2e8f0; }
        .party.client { background: #f0fdfa; border: 1px solid #ccfbf1; }
        .party-label { font-size: 7px; text-transform: uppercase; letter-spacing: 3px; color: #94a3b8; font-weight: 800; margin-bottom: 8px; }
        .party-name { font-size: 14px; font-weight: 900; text-transform: uppercase; }
        .party-detail { font-size: 9px; color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead th { background: #0f172a; color: white; padding: 10px 12px; font-size: 8px; text-transform: uppercase; letter-spacing: 2px; font-weight: 800; text-align: left; }
        thead th:last-child, thead th:nth-child(4), thead th:nth-child(3) { text-align: right; }
        tbody td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 10px; }
        tbody td:last-child, tbody td:nth-child(4), tbody td:nth-child(3) { text-align: right; }
        tfoot td { padding: 8px 12px; font-size: 10px; font-weight: 800; }
        .total-row { background: #0f172a; color: white; font-size: 13px; }
        .footer { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature { width: 45%; text-align: center; padding-top: 50px; border-top: 1px dashed #cbd5e1; }
        .signature p { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; }
        .payments-box { margin-top: 20px; padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; }
        .payments-box h4 { font-size: 8px; text-transform: uppercase; letter-spacing: 2px; color: #16a34a; font-weight: 800; margin-bottom: 8px; }
        .payment-line { font-size: 9px; display: flex; justify-content: space-between; padding: 3px 0; }
        @media print { body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body onload="window.print()">

    <div class="header">
        <div class="logo-area">
            @if(setting('general.company_logo'))
                <img src="{{ media_url(setting('general.company_logo')) }}" alt="Logo" style="max-height: 60px; max-width: 220px; margin-bottom: 6px;">
            @endif
            <h1>{{ setting('general.company_name', 'AviSmart') }}</h1>
            <p>{{ __("Système de Gestion Avicole Intégré") }}</p>
            <p style="margin-top: 8px; font-size: 9px; color: #475569;">{{ setting('general.country', 'Guinée') }}</p>
        </div>
        <div class="doc-info">
            <div class="ref">{{ $sale->reference }}</div>
            <div class="type">{{ $docLabel }}</div>
            <div class="date">{{ __("Date") }} : {{ $sale->sale_date->translatedFormat('d F Y') }}</div>
            @php($delai = (int) setting('ventes.payment_delay_days', 0))
            @if($delai > 0)
                <div class="date">{{ __("Échéance") }} : {{ $sale->sale_date->copy()->addDays($delai)->translatedFormat('d F Y') }}</div>
            @endif
        </div>
    </div>

    <div class="parties">
        <div class="party vendor">
            <div class="party-label">{{ __("Vendeur") }}</div>
            <div class="party-name">AviSmart SARL</div>
            <div class="party-detail" style="margin-top: 6px;">
                {{ __("Conakry, République de Guinée") }}<br>
                {{-- NIF et RCCM à personnaliser --}}
                @if(setting('general.fiscal_id'))
               <small>NIF : {{ setting('general.fiscal_id') }}</small><br>
               @endif
               @if(setting('general.rccm'))
                   <small>RCCM : {{ setting('general.rccm') }}</small>
               @endif
            </div>
        </div>
        <div class="party client">
            <div class="party-label">{{ __("Client") }}</div>
            <div class="party-name">{{ $sale->client->name }}</div>
            <div class="party-detail" style="margin-top: 6px;">
                {{ $sale->client->address ?? '' }}<br>
                {{ __("Tél") }} : {{ $sale->client->phone ?? '—' }}<br>
                @if($sale->type === 'facture' && $sale->client->nif)
                    NIF : {{ $sale->client->nif }}<br>
                    RCCM : {{ $sale->client->rccm ?? '—' }}
                @endif
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 40%;">{{ __("Désignation") }}</th>
                <th>{{ __("Qté") }}</th>
                <th>{{ __("Unité") }}</th>
                <th>{{ __("P.U.") }} ({{ setting('general.currency', 'GNF') }})</th>
                <th>{{ __("Total") }} ({{ setting('general.currency', 'GNF') }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
            <tr>
                <td><strong>{{ $item->product_name }}</strong><br><span style="font-size: 8px; color: #94a3b8; text-transform: uppercase;">{{ $item->type_label }}</span></td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: center; text-transform: uppercase; font-size: 9px;">{{ $item->unit }}</td>
                <td>{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                <td><strong>{{ number_format($item->total, 0, ',', ' ') }}</strong></td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align: right; color: #94a3b8; text-transform: uppercase; font-size: 9px; letter-spacing: 1px;">{{ __("Sous-total HT") }}</td>
                <td style="text-align: right;">{{ number_format($sale->subtotal, 0, ',', ' ') }}</td>
            </tr>
            @if($sale->discount_amount > 0)
            <tr>
                <td colspan="4" style="text-align: right; color: #e11d48; text-transform: uppercase; font-size: 9px; letter-spacing: 1px;">{{ __("Remise") }}</td>
                <td style="text-align: right; color: #e11d48;">− {{ number_format($sale->discount_amount, 0, ',', ' ') }}</td>
            </tr>
            @endif
            @if($sale->tax_rate > 0)
            <tr>
                <td colspan="4" style="text-align: right; color: #94a3b8; text-transform: uppercase; font-size: 9px; letter-spacing: 1px;">{{ __("TVA (:rate%)", ['rate' => $sale->tax_rate]) }}</td>
                <td style="text-align: right;">{{ number_format($sale->tax_amount, 0, ',', ' ') }}</td>
            </tr>
            @endif
            @if($sale->delivery_fee > 0)
            <tr>
                <td colspan="4" style="text-align: right; color: #94a3b8; text-transform: uppercase; font-size: 9px; letter-spacing: 1px;">{{ __("Frais de livraison") }}</td>
                <td style="text-align: right;">{{ number_format($sale->delivery_fee, 0, ',', ' ') }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td colspan="4" style="text-align: right; text-transform: uppercase; letter-spacing: 2px; font-size: 10px;">{{ __("Total TTC") }}</td>
                <td style="text-align: right; font-size: 15px;">{{ number_format($sale->total_amount, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</td>
            </tr>
        </tfoot>
    </table>

    @if($sale->payments->count() > 0)
    <div class="payments-box">
        <h4>{{ __("Paiements enregistrés") }}</h4>
        @foreach($sale->payments as $payment)
        <div class="payment-line">
            <span>{{ $payment->payment_date->format('d/m/Y') }} — {{ $payment->method_label }}</span>
            <span><strong>{{ number_format($payment->amount, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</strong></span>
        </div>
        @endforeach
        <div class="payment-line" style="border-top: 1px solid #86efac; margin-top: 5px; padding-top: 5px; font-weight: 900;">
            <span>{{ __("RESTE DÛ") }}</span>
            <span>{{ number_format($sale->remaining_amount, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</span>
        </div>
    </div>
    @endif

    <div class="footer">
        <div class="signature">
            <p>{{ __("Le Vendeur") }}</p>
            <p style="margin-top: 8px; font-weight: 700; color: #1e293b;">{{ $sale->user->name ?? '' }}</p>
        </div>
        <div class="signature">
            <p>{{ __("Le Client") }}</p>
            <p style="margin-top: 8px; font-weight: 700; color: #1e293b;">{{ $sale->client->name }}</p>
        </div>
    </div>

    @if(setting('ventes.invoice_footer'))
        <div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #64748b; text-align: center;">
            {{ setting('ventes.invoice_footer') }}
        </div>
    @endif

</body>
</html>
