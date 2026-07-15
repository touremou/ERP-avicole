<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ __("Reçu") }} {{ $sale->reference }}</title>
    @php
        $shop  = setting('general.company_name', setting('general.farm_name', 'Ferme'));
        $phone = setting('general.company_phone', '');
        $addr  = setting('general.company_address', '');
    @endphp
    <style>
        /* margin:0 → pas d'URL/date injectées par le navigateur ; marge en padding. */
        @page { size: 80mm auto; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'DejaVu Sans', 'Courier New', monospace; }
        body { width: 72mm; font-size: 11px; color: #000; line-height: 1.5; padding: 4mm; }
        .center { text-align: center; }
        .shop { font-size: 15px; font-weight: 900; text-transform: uppercase; }
        .muted { color: #444; font-size: 9px; }
        .sep { border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        td.r { text-align: right; }
        .item-name { font-weight: 700; }
        .total-row td { font-size: 14px; font-weight: 900; padding-top: 4px; }
        .foot { font-size: 9px; }
        .actions { margin-top: 14px; text-align: center; }
        .actions button, .actions a {
            display: inline-block; margin: 3px; padding: 8px 14px; font-size: 11px; font-weight: 700;
            border: 1px solid #000; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #000;
        }
        .actions .primary { background: #0f172a; color: #fff; border-color: #0f172a; }
        @media print { .no-print { display: none !important; } body { width: auto; } }
    </style>
</head>
<body>
    <div class="center">
        <div class="shop">{{ $shop }}</div>
        @if($addr)<div class="muted">{{ $addr }}</div>@endif
        @if($phone)<div class="muted">Tél : {{ $phone }}</div>@endif
    </div>

    <div class="sep"></div>

    <table>
        <tr><td>{{ __("Reçu") }}</td><td class="r"><strong>{{ $sale->reference }}</strong></td></tr>
        <tr><td>{{ __("Date") }}</td><td class="r">{{ $sale->sale_date->format('d/m/Y') }} {{ $sale->created_at->format('H:i') }}</td></tr>
        <tr><td>{{ __("Caissier") }}</td><td class="r">{{ $sale->user?->name ?? '—' }}</td></tr>
        @if($sale->sellerEmployee)
        <tr><td>{{ __("Vendeur") }}</td><td class="r">{{ $sale->sellerEmployee->first_name }} {{ $sale->sellerEmployee->last_name }}</td></tr>
        @endif
        <tr><td>{{ __("Client") }}</td><td class="r">{{ $sale->client->name }}</td></tr>
    </table>

    <div class="sep"></div>

    <table>
        @foreach($sale->items as $item)
        <tr>
            <td colspan="2" class="item-name">{{ $item->product_name }}</td>
        </tr>
        <tr>
            <td class="muted">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }} {{ $item->unit }} × {{ number_format($item->unit_price, 0, ',', ' ') }}</td>
            <td class="r">{{ number_format($item->total, 0, ',', ' ') }}</td>
        </tr>
        @endforeach
    </table>

    <div class="sep"></div>

    <table>
        <tr class="total-row"><td>{{ __("TOTAL") }}</td><td class="r">{{ number_format($sale->total_amount, 0, ',', ' ') }} {{ currency() }}</td></tr>
        <tr><td>{{ __("Payé") }}</td><td class="r">{{ number_format($sale->paid_amount, 0, ',', ' ') }} {{ currency() }}</td></tr>
        @php $method = optional($sale->payments->first())->method; @endphp
        @if($method)<tr><td>{{ __("Mode") }}</td><td class="r">{{ ['especes'=>'Espèces','orange_money'=>'Orange Money','virement'=>'Virement','cheque'=>'Chèque'][$method] ?? $method }}</td></tr>@endif
    </table>

    @php
        $ticketFooter = trim((string) setting('ventes.ticket_footer', 'Merci de votre achat !'));
        $ticketNote   = trim((string) setting('ventes.ticket_note', 'Conservez ce reçu pour tout échange ou retour.'));
    @endphp
    @if($ticketFooter !== '' || $ticketNote !== '')
    <div class="sep"></div>
    <div class="center foot">
        @if($ticketFooter !== ''){{ $ticketFooter }}@endif
        @if($ticketFooter !== '' && $ticketNote !== '')<br>@endif
        @if($ticketNote !== ''){{ $ticketNote }}@endif
    </div>
    @endif

    <div class="actions no-print">
        <button onclick="window.print()" class="primary">🖨 {{ __("Réimprimer") }}</button>
        <a href="{{ route('pos.index') }}" class="primary">＋ {{ __("Nouvelle vente") }}</a>
        <a href="{{ route('sales.show', $sale) }}">{{ __("Voir la vente") }}</a>
    </div>

    @if(setting('ventes.ticket_autoprint', true))
    {{-- Impression automatique, puis retour au POS une fois le dialogue fermé
         (imprimé OU annulé) pour enchaîner la vente suivante sans navigation manuelle. --}}
    <script>
        let posRedirected = false;
        const backToPos = () => {
            if (posRedirected) return;
            posRedirected = true;
            setTimeout(() => { window.location.href = @json(route('pos.index')); }, 600);
        };
        window.addEventListener('afterprint', backToPos);
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
    @endif
</body>
</html>
