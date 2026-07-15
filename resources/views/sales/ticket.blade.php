<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->reference }} — {{ __('Ticket') }}</title>
    @php
        $shop   = setting('general.company_name', 'AviSmart');
        $nif    = setting('general.fiscal_id', '');
        $rccm   = setting('general.rccm', '');
        $footer = trim((string) setting('ventes.invoice_footer', 'Merci pour votre confiance.'));
        $paid   = (float) $sale->total_amount - (float) $sale->remaining_amount;
        $docLabel = match ($sale->type) {
            'facture'  => 'FACTURE',
            'comptant' => 'TICKET DE CAISSE',
            default    => 'BON DE LIVRAISON',
        };
    @endphp
    <style>
        /* margin:0 → pas d'URL/date injectées par le navigateur ; marge en padding. */
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { width: 72mm; margin: 0 auto; padding: 4mm; font-family: "Courier New", monospace; font-size: 11px; color: #000; line-height: 1.5; }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .lg { font-size: 14px; }
        .muted { color: #333; font-size: 10px; }
        hr { border: none; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .right { text-align: right; }
        .row td { font-size: 10px; }
        .tot td { font-weight: 700; }
        .actions { margin-top: 14px; text-align: center; }
        .actions button { font-family: inherit; padding: 8px 18px; border: 1px solid #000; background: #000; color: #fff; font-weight: 700; cursor: pointer; border-radius: 4px; }
        @media print { .no-print { display: none !important; } body { width: auto; } }
    </style>
</head>
<body onload="window.print()">
    <div class="center">
        @if(setting('general.company_logo'))
            <img src="{{ media_url(setting('general.company_logo')) }}" alt="" style="max-height:42px;max-width:60mm;margin-bottom:4px;">
        @endif
        <div class="bold lg">{{ $shop }}</div>
        @if($nif)<div class="muted">NIF : {{ $nif }}</div>@endif
        @if($rccm)<div class="muted">RCCM : {{ $rccm }}</div>@endif
    </div>

    <hr>
    <div class="center bold">{{ $docLabel }}</div>
    <div class="center">{{ $sale->reference }}</div>
    <div class="muted center">{{ $sale->created_at->format('d/m/Y H:i') }}</div>
    <div class="muted">{{ __('Client') }} : {{ $sale->client->name ?? '—' }}</div>
    <hr>

    <table>
        @foreach($sale->items as $item)
        <tr class="row">
            <td>{{ $item->product_name }}</td>
            <td class="right">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }} {{ $item->unit }}</td>
        </tr>
        <tr class="row">
            <td class="muted">&nbsp;&nbsp;× {{ number_format($item->unit_price, 0, ',', ' ') }}</td>
            <td class="right">{{ number_format($item->total, 0, ',', ' ') }}</td>
        </tr>
        @endforeach
    </table>

    <hr>
    <table>
        @if($sale->discount_amount > 0)
        <tr class="row"><td>{{ __('Sous-total') }}</td><td class="right">{{ number_format($sale->subtotal, 0, ',', ' ') }}</td></tr>
        <tr class="row"><td>{{ __('Remise') }}</td><td class="right">− {{ number_format($sale->discount_amount, 0, ',', ' ') }}</td></tr>
        @endif
        <tr class="tot"><td>{{ __('TOTAL') }}</td><td class="right">{{ number_format($sale->total_amount, 0, ',', ' ') }} {{ currency() }}</td></tr>
        <tr class="row"><td>{{ __('Payé') }}</td><td class="right">{{ number_format($paid, 0, ',', ' ') }}</td></tr>
        @if($sale->remaining_amount > 0)
        <tr class="row bold"><td>{{ __('Reste dû') }}</td><td class="right">{{ number_format($sale->remaining_amount, 0, ',', ' ') }}</td></tr>
        @endif
    </table>

    <hr>
    <div class="center muted">{{ $footer }}</div>

    <div class="actions no-print">
        <button onclick="window.print()">🖨 {{ __('Imprimer') }}</button>
    </div>
</body>
</html>
