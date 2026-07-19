<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Dossier de lot — {{ $order->order_number }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        h2 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #be123c; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; margin: 14px 0 6px; }
        .muted { color: #64748b; }
        .header { margin-bottom: 10px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th { background: #f1f5f9; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; color: #64748b; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #f1f5f9; }
        .badge-nc { color: #dc2626; font-weight: bold; }
        .badge-ok { color: #059669; font-weight: bold; }
        .blocked { background: #fef2f2; border: 1px solid #fecaca; padding: 6px 8px; margin: 6px 0; }
        .footer { position: fixed; bottom: -12px; left: 0; right: 0; font-size: 7px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Dossier de lot — {{ $order->order_number }}</h1>
        <p class="muted" style="margin:0">{{ $farm }} — généré le {{ $generatedAt->format('d/m/Y H:i') }} —
            statut : <strong>{{ strtoupper($order->status) }}</strong>
            @if($order->result?->carcass_yield_percent) — rendement carcasse {{ $order->result->carcass_yield_percent }} % @endif
        </p>
    </div>

    @if($order->isBlocked())
        <div class="blocked"><strong>⛔ LOT BLOQUÉ</strong> — {{ $order->blocked_reason }}<br>
            Par {{ $order->blockedBy?->name ?? '—' }} le {{ $order->blocked_at?->format('d/m/Y H:i') }}</div>
    @elseif($order->released_at)
        <div class="blocked" style="background:#ecfdf5;border-color:#a7f3d0"><strong>Libéré</strong> le {{ $order->released_at->format('d/m/Y H:i') }} par {{ $order->releasedBy?->name ?? '—' }} — {{ $order->release_reason }}</div>
    @endif

    <h2>1. Origine (amont)</h2>
    @if($order->reception)
        <p style="margin:0">Réception externe n°{{ $order->reception->id }} — éleveur <strong>{{ $order->reception->provider?->name ?? '—' }}</strong>, {{ $order->reception->reception_date->format('d/m/Y') }}.<br>
            {{ $order->reception->received_quantity }} sujets reçus ({{ $order->reception->rejected_quantity }} écartés) · {{ number_format((float) $order->reception->total_live_weight_kg, 1, ',', ' ') }} kg vif ·
            état {{ $order->reception->sanitary_state }} · diète {{ $order->reception->fasting_respected }} ·
            décision <strong>{{ strtoupper(str_replace('_', ' ', $order->reception->decision)) }}</strong>@if($order->reception->decision_reason) — {{ $order->reception->decision_reason }}@endif<br>
            <span class="muted">Contrôleur : {{ $order->reception->controller?->name ?? '—' }} · relevé {{ $order->reception->releve_at?->format('d/m/Y H:i') }} · synchronisé {{ $order->reception->synced_at?->format('d/m/Y H:i') ?? '—' }}</span><br>
            @php
                $rec = $order->reception;
                if ($rec->origin === 'facon') {
                    $originLine = 'Origine : à façon — sujets propriété du client, sans coût matière.';
                } elseif ($rec->purchase_total_cost) {
                    $originLine = 'Origine : achat — coût ' . number_format((float) $rec->purchase_total_cost, 0, ',', ' ')
                        . ' ' . setting('general.currency', 'GNF')
                        . ($rec->supplierInvoice ? ' · facture ' . $rec->supplierInvoice->reference . ' (' . $rec->supplierInvoice->status . ')' : '') . '.';
                } else {
                    $originLine = 'Origine : achat — prix à saisir au bureau.';
                }
            @endphp
            <strong>{{ $originLine }}</strong>
        </p>
    @elseif($order->batch)
        <p style="margin:0">Lot interne <strong>{{ $order->batch->code }}</strong> — {{ $order->batch->building?->name ?? '—' }} · arrivée {{ $order->batch->arrival_date }}</p>
    @else
        <p style="margin:0" class="muted">—</p>
    @endif

    <h2>2. Ordre & exécution</h2>
    <p style="margin:0">Planifié le {{ $order->planned_date?->format('d/m/Y') }} ({{ $order->planned_quantity }} sujets) par {{ $order->requester?->name ?? '—' }}.
    @if($order->result)
        <br>Exécuté le {{ $order->actual_date?->format('d/m/Y') }} par {{ $order->executor?->name ?? '—' }} :
        {{ $order->actual_quantity }} sujets · {{ number_format((float) $order->total_live_weight_kg, 1, ',', ' ') }} kg vif → {{ number_format((float) $order->result->total_carcass_weight_kg, 1, ',', ' ') }} kg carcasse.
        @if($order->result->condemned_count)<br><span class="badge-nc">{{ $order->result->condemned_count }} condamnés</span> — {{ $order->result->condemned_reason ?? '—' }}@endif
    @else
        <br><span class="muted">Non exécuté à ce jour.</span>
    @endif
    </p>

    <h2>3. Points critiques (CCP)</h2>
    @if($order->ccpRecords->isEmpty())
        <p style="margin:0" class="muted">Aucun relevé CCP rattaché à cet ordre.</p>
    @else
        <table class="data">
            <thead><tr><th>CCP</th><th>Mesures</th><th>Conforme</th><th>Action corrective</th><th>Opérateur</th><th>Relevé le</th><th>Synchronisé le</th></tr></thead>
            <tbody>
                @foreach($order->ccpRecords as $record)
                <tr>
                    <td>{{ \App\Models\CcpRecord::labelFor($record->ccp) }}</td>
                    <td>@foreach($record->mesures as $key => $value){{ $key }}={{ is_scalar($value) ? $value : json_encode($value) }}@if(!$loop->last), @endif @endforeach</td>
                    <td class="{{ $record->conforme ? 'badge-ok' : 'badge-nc' }}">{{ $record->conforme ? 'OUI' : 'NON' }}</td>
                    <td>{{ $record->corrective_action ?? '—' }}</td>
                    <td>{{ $record->operator?->name ?? '—' }}</td>
                    <td>{{ $record->releve_at?->format('d/m/Y H:i') }}</td>
                    <td>{{ $record->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>4. Aval (produits)</h2>
    @if($order->cuttingSessions->isEmpty())
        <p style="margin:0" class="muted">Pas de découpe — carcasses entières.</p>
    @else
        @foreach($order->cuttingSessions as $session)
            <p style="margin:2px 0"><strong>Découpe du {{ $session->session_date?->format('d/m/Y') ?? '—' }}</strong> — {{ number_format((float) $session->total_input_kg, 1, ',', ' ') }} kg entrés :
            @foreach($session->products as $product){{ $product->product_name ?? $product->product_type }} {{ number_format((float) $product->quantity_kg, 1, ',', ' ') }} kg{{ $loop->last ? '' : ', ' }}@endforeach</p>
        @endforeach
    @endif

    @if($order->byproducts->isNotEmpty())
        <p style="margin:4px 0 0"><strong>Sous-produits :</strong>
        @foreach($order->byproducts as $byproduct){{ \App\Models\SlaughterByproduct::TYPES[$byproduct->type] ?? $byproduct->type }} {{ number_format((float) $byproduct->quantity_kg, 1, ',', ' ') }} kg → {{ \App\Models\SlaughterByproduct::DESTINATIONS[$byproduct->destination] ?? $byproduct->destination }}@if(!$loop->last), @endif @endforeach</p>
    @endif

    @if($order->client)
        <p style="margin:4px 0 0">Client destinataire : <strong>{{ $order->client->name }}</strong></p>
    @endif

    @if($order->isFacon())
        <h2>5. Prestation d'abattage à façon</h2>
        <p style="margin:0">Modèle : <strong>{{ \App\Models\SlaughterOrder::BILLING_MODELS[$order->billing_model] ?? '—' }}</strong> · tarif figé : {{ number_format((float) $order->billing_rate, 0, ',', ' ') }} GNF ·
        prestation : <strong>{{ $order->service_fee ? number_format((float) $order->service_fee, 0, ',', ' ') . ' GNF' : 'à l\'exécution' }}</strong>
        @if($order->serviceSale) · facture {{ $order->serviceSale->reference }} ({{ $order->serviceSale->status }})@endif<br>
        <span class="muted">Les produits restent propriété du client (RG-07) — aucun n'entre au stock vendable.</span></p>
    @else
        @php $eco = $order->economicSummary(); @endphp
        @if($eco['cost'] > 0 || $eco['output_value'] > 0)
        <h2>5. Économie du lot (marge par gamme)</h2>
        <p style="margin:0 0 4px">{{ $eco['cost_label'] ?? 'Coût matière' }} : <strong>{{ number_format((float) $eco['cost'], 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</strong>@if($eco['cost_per_kg'] > 0) · {{ number_format((float) $eco['cost_per_kg'], 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }} /kg @endif</p>
        @if(count($eco['gammes'] ?? []))
        <table class="data">
            <thead><tr><th>Gamme</th><th class="amount" style="text-align:right;">Valeur</th><th class="amount" style="text-align:right;">Coût</th><th class="amount" style="text-align:right;">Marge</th></tr></thead>
            <tbody>
                @foreach($eco['gammes'] as $g)
                <tr><td>{{ $g['label'] }}</td><td class="amount">{{ number_format((float) $g['value'], 0, ',', ' ') }}</td><td class="amount text-neg">{{ number_format((float) $g['cost'], 0, ',', ' ') }}</td><td class="amount">{{ number_format((float) $g['margin'], 0, ',', ' ') }}</td></tr>
                @endforeach
            </tbody>
        </table>
        @endif
        <p style="margin:0">Marge directe du lot : <strong>{{ number_format((float) $eco['margin'], 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</strong>
        @if($eco['has_unpriced']) · <span class="muted">des sorties sans prix de vente ne sont pas valorisées — marge plancher.</span>@endif</p>
        @endif
    @endif

    <div class="footer">Document généré par AviSmart — dossier de lot HACCP (horodatages relevé/synchronisation conservés). {{ $farm }} — {{ $generatedAt->format('d/m/Y H:i') }}</div>
</body>
</html>
