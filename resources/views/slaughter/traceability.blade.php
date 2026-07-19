<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Dossier de lot — :number', ['number' => $order->order_number])" :subtitle="__('Traçabilité complète : amont → CCP → produits')" icon="fa-route" accent="rose" :back="route('slaughter.dashboard')">
            <x-slot name="actions">
                <a href="{{ route('slaughter.orders.traceability', [$order, 'format' => 'pdf']) }}" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-700 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-file-pdf mr-1"></i> {{ __("Dossier PDF") }}</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            {{-- STATUT --}}
            <div class="bg-white p-6 rounded-[2.5rem] border {{ $order->isBlocked() ? 'border-red-300 bg-red-50/50' : 'border-slate-100' }} shadow-sm flex items-center justify-between flex-wrap gap-4">
                <div>
                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest m-0">{{ __("Statut du lot") }}</p>
                    <p class="text-lg font-black m-0 uppercase {{ $order->isBlocked() ? 'text-red-600' : 'text-slate-800' }}">
                        {{ $order->isBlocked() ? '⛔ ' : '' }}{{ $order->status }}
                    </p>
                    @if($order->isBlocked())
                        <p class="text-[10px] text-red-600 m-0">{{ $order->blocked_reason }} — {{ $order->blockedBy?->name }}, {{ $order->blocked_at?->format('d/m/Y H:i') }}</p>
                    @elseif($order->released_at)
                        <p class="text-[10px] text-emerald-600 m-0">{{ __("Libéré le :date par :name — :reason", ['date' => $order->released_at->format('d/m/Y H:i'), 'name' => $order->releasedBy?->name, 'reason' => $order->release_reason]) }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest m-0">{{ __("Rendement carcasse") }}</p>
                    <p class="text-lg font-black text-slate-800 m-0">{{ $order->result?->carcass_yield_percent ? $order->result->carcass_yield_percent . ' %' : '—' }}</p>
                </div>
                @if($order->isFacon())
                <div class="text-right">
                    <p class="text-[8px] font-black uppercase text-amber-600 tracking-widest m-0"><i class="fa-solid fa-handshake mr-1"></i>{{ __("Prestation à façon") }}</p>
                    <p class="text-lg font-black text-amber-600 m-0">{{ $order->service_fee ? number_format((float) $order->service_fee, 0, ',', ' ') . ' GNF' : '—' }}</p>
                    <p class="text-[9px] text-slate-500 m-0">
                        {{ __(\App\Models\SlaughterOrder::BILLING_MODELS[$order->billing_model] ?? '—') }} · {{ number_format((float) $order->billing_rate, 0, ',', ' ') }} GNF
                        @if($order->serviceSale) · {{ __("facture") }} <span class="font-black">{{ $order->serviceSale->reference }}</span> ({{ $order->serviceSale->status }}) @endif
                    </p>
                    <p class="text-[8px] text-amber-600 m-0">{{ __("Produits propriété du client — hors stock vendable (RG-07).") }}</p>
                </div>
                @endif
            </div>

            {{-- 1. AMONT --}}
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-3"><i class="fa-solid fa-arrow-up-from-bracket mr-1"></i> {{ __("1. Origine (amont)") }}</p>
                @if($order->reception)
                    <p class="text-xs m-0"><span class="font-black">{{ __("Réception externe n°:id", ['id' => $order->reception->id]) }}</span> — {{ __("éleveur") }} <span class="font-black">{{ $order->reception->provider?->name ?? '—' }}</span>, {{ $order->reception->reception_date->format('d/m/Y') }}</p>
                    <p class="text-[10px] text-slate-500 m-0">
                        {{ $order->reception->received_quantity }} {{ __("sujets reçus") }} ({{ $order->reception->rejected_quantity }} {{ __("écartés") }}) · {{ number_format((float) $order->reception->total_live_weight_kg, 1, ',', ' ') }} kg vif ·
                        {{ __("état") }} {{ $order->reception->sanitary_state }} · {{ __("diète") }} {{ $order->reception->fasting_respected }} ·
                        {{ __("décision") }} <span class="font-black uppercase">{{ str_replace('_', ' ', $order->reception->decision) }}</span>
                        @if($order->reception->decision_reason) — {{ $order->reception->decision_reason }} @endif
                    </p>
                    <p class="text-[9px] text-slate-400 m-0">{{ __("Contrôleur") }} : {{ $order->reception->controller?->name ?? '—' }} · {{ __("relevé") }} {{ $order->reception->releve_at?->format('d/m/Y H:i') }}</p>
                    <p class="text-[10px] m-0 mt-1">
                        @if($order->reception->origin === 'facon')
                            <span class="text-[8px] font-black uppercase px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600">🤝 {{ __("À façon") }}</span>
                            <span class="text-slate-400">{{ __("— sujets du client, sans coût matière") }}</span>
                        @else
                            <span class="text-[8px] font-black uppercase px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600">🛒 {{ __("Achat") }}</span>
                            @if($order->reception->purchase_total_cost)
                                <span class="font-black text-slate-800">{{ number_format($order->reception->purchase_total_cost, 0, ',', ' ') }} {{ currency() }}</span>
                                @if($order->reception->supplierInvoice)
                                    @can('depenses.L')
                                    · <a href="{{ route('purchases.show', $order->reception->supplierInvoice->id) }}" class="no-underline {{ $order->reception->supplierInvoice->status === 'valide' ? 'text-emerald-600' : 'text-amber-600' }} hover:underline"><i class="fa-solid fa-file-invoice-dollar"></i> {{ $order->reception->supplierInvoice->reference }} ({{ __($order->reception->supplierInvoice->status) }})</a>
                                    @endcan
                                @endif
                            @else
                                <span class="text-slate-400">{{ __("— prix à saisir au bureau") }}</span>
                            @endif
                        @endif
                    </p>
                @elseif($order->batch)
                    <p class="text-xs m-0"><span class="font-black">{{ __("Lot interne") }} {{ $order->batch->code }}</span> — {{ $order->batch->building?->name ?? '—' }}</p>
                    <p class="text-[10px] text-slate-500 m-0">{{ __("Arrivée du lot") }} : {{ $order->batch->arrival_date }} · {{ __("effectif actuel") }} : {{ $order->batch->current_quantity }}</p>
                @else
                    <p class="text-[10px] text-slate-400 m-0">—</p>
                @endif
            </div>

            {{-- 2. ORDRE & EXÉCUTION --}}
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-3"><i class="fa-solid fa-clipboard-list mr-1"></i> {{ __("2. Ordre & exécution") }}</p>
                <p class="text-xs m-0">{{ __("Planifié le") }} <span class="font-black">{{ $order->planned_date?->format('d/m/Y') }}</span> ({{ $order->planned_quantity }} {{ __("sujets") }}) {{ __("par") }} {{ $order->requester?->name ?? '—' }}</p>
                @if($order->result)
                    <p class="text-[10px] text-slate-500 m-0">
                        {{ __("Exécuté le") }} {{ $order->actual_date?->format('d/m/Y') }} {{ __("par") }} {{ $order->executor?->name ?? '—' }} ·
                        {{ $order->actual_quantity }} {{ __("sujets") }} · {{ number_format((float) $order->total_live_weight_kg, 1, ',', ' ') }} kg {{ __("vif") }} →
                        {{ number_format((float) $order->result->total_carcass_weight_kg, 1, ',', ' ') }} kg {{ __("carcasse") }}
                        @if($order->result->condemned_count) · <span class="text-red-600 font-black">{{ $order->result->condemned_count }} {{ __("condamnés") }}</span> ({{ $order->result->condemned_reason ?? '—' }}) @endif
                    </p>
                @else
                    <p class="text-[10px] text-slate-400 m-0">{{ __("Non exécuté à ce jour.") }}</p>
                @endif
            </div>

            {{-- 3. CONTRÔLES CCP --}}
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-3"><i class="fa-solid fa-shield-halved mr-1"></i> {{ __("3. Points critiques (CCP)") }}</p>
                @forelse($order->ccpRecords as $record)
                    <div class="flex items-start gap-3 py-2 border-b border-slate-50 last:border-0">
                        <span class="text-sm">{{ $record->conforme ? '✅' : '🚨' }}</span>
                        <div>
                            <p class="text-[11px] font-black m-0">{{ \App\Models\CcpRecord::labelFor($record->ccp) }}
                                <span class="text-slate-400 font-bold">· {{ $record->releve_at?->format('d/m/Y H:i') }} · {{ $record->operator?->name ?? '—' }}</span>
                            </p>
                            <p class="text-[10px] text-slate-500 m-0">
                                @foreach($record->mesures as $key => $value){{ $key }} : <span class="font-black">{{ is_scalar($value) ? $value : json_encode($value) }}</span>@if(!$loop->last) · @endif @endforeach
                            </p>
                            @if(! $record->conforme)
                                <p class="text-[10px] text-red-600 m-0">{{ __("Action corrective") }} : {{ $record->corrective_action }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-[10px] text-amber-600 m-0"><i class="fa-solid fa-triangle-exclamation mr-1"></i> {{ __("Aucun relevé CCP rattaché à cet ordre.") }}</p>
                @endforelse
            </div>

            {{-- 4. AVAL : découpes, produits, sous-produits --}}
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-3"><i class="fa-solid fa-arrow-down-wide-short mr-1"></i> {{ __("4. Aval (produits)") }}</p>
                @forelse($order->cuttingSessions as $session)
                    <p class="text-xs font-black m-0">{{ __("Découpe du :date — :kg kg entrés", ['date' => $session->session_date?->format('d/m/Y') ?? '—', 'kg' => number_format((float) $session->total_input_kg, 1, ',', ' ')]) }}</p>
                    <p class="text-[10px] text-slate-500 mb-2">
                        @foreach($session->products as $product){{ $product->product_name ?? $product->product_type }} : <span class="font-black">{{ number_format((float) $product->quantity_kg, 1, ',', ' ') }} kg</span>@if(!$loop->last) · @endif @endforeach
                    </p>
                @empty
                    <p class="text-[10px] text-slate-400 mb-2">{{ __("Pas de découpe — carcasses entières.") }}</p>
                @endforelse

                @if($order->byproducts->isNotEmpty())
                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-widest mt-3 mb-1">{{ __("Sous-produits") }}</p>
                    <p class="text-[10px] text-slate-500 m-0">
                        @foreach($order->byproducts as $byproduct){{ \App\Models\SlaughterByproduct::TYPES[$byproduct->type] ?? $byproduct->type }} : <span class="font-black">{{ number_format((float) $byproduct->quantity_kg, 1, ',', ' ') }} kg</span> → {{ \App\Models\SlaughterByproduct::DESTINATIONS[$byproduct->destination] ?? $byproduct->destination }}@if(!$loop->last) · @endif @endforeach
                    </p>
                @endif

                @if($order->client)
                    <p class="text-[10px] text-slate-500 mt-2 m-0">{{ __("Client destinataire") }} : <span class="font-black">{{ $order->client->name }}</span></p>
                @endif
            </div>

            {{-- 5. ÉCONOMIE DU LOT — marge directe (coût d'achat vif vs valeur produite).
                 Donnée financière : réservée au périmètre Dépenses. --}}
            @can('depenses.L')
            @php $eco = $order->economicSummary(); @endphp
            @if($eco['mode'] === 'facon' || $eco['cost'] > 0 || $eco['output_value'] > 0)
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-3"><i class="fa-solid fa-scale-balanced mr-1"></i> {{ __("5. Économie du lot") }}</p>
                @if($eco['mode'] === 'facon')
                    <div class="flex justify-between items-center py-2 border-b border-slate-50">
                        <span class="text-xs font-black text-slate-600">{{ __("Prestation d'abattage à façon") }}</span>
                        <span class="text-sm font-black text-emerald-600">{{ number_format($eco['margin'], 0, ',', ' ') }} {{ currency() }}</span>
                    </div>
                    <p class="text-[9px] text-slate-400 m-0 mt-2">{{ __("Sujets propriété du client — aucun coût matière (RG-07).") }}</p>
                @else
                    <div class="flex justify-between items-center py-2 border-b border-slate-50">
                        <span class="text-xs font-black text-slate-600">{{ __("Valeur produite (découpes valorisées)") }}</span>
                        <span class="text-sm font-black text-slate-900">{{ number_format($eco['output_value'], 0, ',', ' ') }} {{ currency() }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-slate-50">
                        <span class="text-xs font-black text-slate-600">− {{ __($eco['cost_label'] ?? 'Coût direct') }}</span>
                        <span class="text-sm font-black text-rose-500">{{ number_format($eco['cost'], 0, ',', ' ') }} {{ currency() }}</span>
                    </div>
                    <div class="flex justify-between items-center pt-3">
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">{{ __("Marge directe") }}</span>
                        <span class="text-lg font-black {{ $eco['margin'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format($eco['margin'], 0, ',', ' ') }} {{ currency() }}</span>
                    </div>
                    @if($eco['mode'] === 'interne')
                        <p class="text-[9px] text-slate-400 m-0 mt-2">{{ __("Lot interne : le coût d'acquisition est suivi au niveau du lot (P&L), non reventilé ici.") }}</p>
                    @endif
                    @if($eco['has_unpriced'])
                        <p class="text-[9px] text-amber-600 m-0 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i> {{ __("Des découpes sans prix ne sont pas valorisées — la marge est un plancher.") }}</p>
                    @endif
                @endif
            </div>
            @endif
            @endcan
        </div>
    </div>
</x-app-layout>
