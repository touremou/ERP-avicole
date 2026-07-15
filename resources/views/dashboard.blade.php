<x-app-layout>
    @php
        $isOffline = $offline_mode ?? false;
        $totalBirdsCount = $totalBirds ?? 0;
        $mortalityRateDisplay = number_format($globalMortalityRate ?? 0, 1);
        $hdp = $hdp ?? 0;
    @endphp

    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-slate-900 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-house-signal text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Console de Commande") }}</h2>
                    <p class="text-[10px] font-black text-blue-600 uppercase tracking-[0.2em] mt-2 italic animate-pulse">
                        {{ now()->translatedFormat('d F Y') }} — LIVE
                    </p>
                </div>
            </div>

            <div class="flex gap-4">
                {{-- Données financières/stock masquées selon les droits modules :
                     valorisation stock → logistique.L ; marge & encours → commerce.L. --}}
                @can('logistique.L')
                {{-- ENRICHI : Info bulle CMUP --}}
                <div class="bg-white px-6 py-4 rounded-[1.5rem] border border-slate-100 text-right shadow-sm group">
                    <p class="text-[8px] font-black text-slate-400 uppercase italic mb-1 flex items-center justify-end gap-1.5">
                        {{ __("Valeur Mat. Premières") }}
                        <i class="fa-solid fa-circle-info text-slate-300 group-hover:text-blue-500 transition-colors cursor-help" title="{{ __('Valorisation basée sur le Coût Moyen Unitaire Pondéré (CMUP) des derniers achats') }}"></i>
                    </p>
                    <p class="text-base font-black text-slate-900 leading-none">{{ number_format($rawMaterialsValue ?? 0, 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ currency() }}</small></p>
                </div>
                @endcan
                
                @can('commerce.L')
                {{-- ENRICHI : Encours clients (trésorerie) --}}
                <div class="bg-white px-6 py-4 rounded-[1.5rem] border border-slate-100 text-right shadow-sm group">
                    <p class="text-[8px] font-black text-slate-400 uppercase italic mb-1 flex items-center justify-end gap-1.5">
                        {{ __("Encours Clients") }}
                        <i class="fa-solid fa-circle-info text-slate-300 group-hover:text-rose-500 transition-colors cursor-help" title="{{ __('Montant des ventes non soldées encore dû à la ferme (créances clients)') }}"></i>
                    </p>
                    <p @class(['text-base font-black leading-none', 'text-rose-600' => ($encoursClients ?? 0) > 0, 'text-slate-900' => ($encoursClients ?? 0) <= 0])>{{ number_format($encoursClients ?? 0, 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ currency() }}</small></p>
                </div>

                {{-- ENRICHI : Info bulle Marge + Changement de label --}}
                <div class="bg-slate-900 px-6 py-4 rounded-[1.5rem] text-right shadow-2xl border-l-4 border-emerald-500 group">
                    <p class="text-[8px] font-black text-emerald-400 uppercase italic mb-1 flex items-center justify-end gap-1.5">
                        {{ __("Marge Nette Mensuelle") }}
                        <i class="fa-solid fa-circle-info text-slate-600 group-hover:text-emerald-300 transition-colors cursor-help" title="{{ __('CA du mois (ventes validées + lait) − charges réelles (aliment + santé + dépenses validées)') }}"></i>
                    </p>
                    <p class="text-base font-black text-white leading-none">{{ number_format($safeProfit ?? 0, 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ currency() }}</small></p>
                </div>
                @endcan

                <a href="{{ route('dashboard.config') }}" title="{{ __('Personnaliser mon tableau de bord') }}"
                   class="w-11 h-11 bg-white border border-slate-200 rounded-2xl flex items-center justify-center text-slate-400 hover:text-slate-900 hover:border-slate-300 transition-all no-underline shadow-sm shrink-0">
                    <i class="fa-solid fa-sliders"></i>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            {{-- ABONNEMENT : carte « Durée de validité » (visible si licence armée) --}}
            @include('dashboard._license-card')

            {{-- BANDEAU D'ALERTES PRIORISÉ (centre de contrôle unifié) --}}
            @if(!empty($priorityAlerts) && $priorityAlerts->isNotEmpty() && dashboard_block_visible('priority_alerts'))
            @php
                $alertStyles = [
                    'critique'  => ['dot' => 'bg-rose-500', 'badge' => 'bg-rose-50 text-rose-600 border-rose-100', 'icon' => 'text-rose-500'],
                    'attention' => ['dot' => 'bg-amber-500', 'badge' => 'bg-amber-50 text-amber-600 border-amber-100', 'icon' => 'text-amber-500'],
                    'info'      => ['dot' => 'bg-blue-500', 'badge' => 'bg-blue-50 text-blue-600 border-blue-100', 'icon' => 'text-blue-500'],
                ];
                $critiqueCount = $priorityAlerts->where('level', 'critique')->count();
            @endphp
            <div class="mb-8" x-data="{ open: {{ $critiqueCount > 0 ? 'true' : 'false' }} }">
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <button type="button" @click="open = !open"
                        class="w-full flex items-center justify-between px-7 py-5 cursor-pointer hover:bg-slate-50 transition-colors border-none bg-transparent text-left">
                        <div class="flex items-center gap-4">
                            <span class="relative flex h-3 w-3">
                                @if($critiqueCount > 0)
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                                @endif
                                <span class="relative inline-flex rounded-full h-3 w-3 {{ $critiqueCount > 0 ? 'bg-rose-500' : 'bg-amber-500' }}"></span>
                            </span>
                            <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic">
                                {{ __("Alertes Critiques") }}
                            </h3>
                            <span class="text-[9px] font-black uppercase px-3 py-1 rounded-full border bg-rose-50 text-rose-600 border-rose-100">
                                {{ $priorityAlerts->count() }} {{ __("critique(s)") }}
                            </span>
                        </div>
                        <i class="fa-solid fa-chevron-down text-slate-300 transition-transform" :class="{ 'rotate-180': open }"></i>
                    </button>

                    <div x-show="open" x-transition class="px-5 pb-5 space-y-2">
                        @foreach($priorityAlerts as $alert)
                        @php $st = $alertStyles[$alert['level']] ?? $alertStyles['info']; @endphp
                        <a href="{{ $alert['url'] ?? '#' }}"
                           class="flex items-center gap-4 px-5 py-3.5 rounded-2xl border border-slate-100 hover:border-slate-200 hover:shadow-sm transition-all no-underline group">
                            <i class="fa-solid {{ $alert['icon'] }} {{ $st['icon'] }} text-base w-5 text-center"></i>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-black uppercase text-slate-800 tracking-tight italic leading-none">{{ $alert['title'] }}</p>
                                <p class="text-[10px] font-bold text-slate-400 italic mt-1 truncate">{{ $alert['detail'] }}</p>
                            </div>
                            <span class="text-[8px] font-black uppercase px-2.5 py-1 rounded-lg border {{ $st['badge'] }} shrink-0">{{ $alert['level'] }}</span>
                            <i class="fa-solid fa-chevron-right text-slate-200 group-hover:text-slate-400 transition-colors text-[10px]"></i>
                        </a>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            {{-- CENTRE DE CONTRÔLE DES ALERTES --}}
            @if(dashboard_block_visible('control_center'))
            <div class="mb-10 space-y-4">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    {{-- BLOC A : AUTONOMIE SILOS --}}
                    <div class="lg:col-span-2 bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-[10px] font-black uppercase text-slate-400 tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-wheat-awn text-amber-500"></i> {{ __("Autonomie des Silos") }}
                            </h4>
                            <span class="text-[8px] bg-slate-100 text-slate-600 px-2 py-1 rounded-md font-black uppercase">{{ __("Seuil") }} : {{ $criticalDaysThreshold ?? 3 }} {{ __("jours") }}</span>
                        </div>
                        
                        @if(count($criticalTypes ?? []) > 0)
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                @foreach($criticalTypes as $alert)
                                    @php
                                        $alertDays   = $alert['days'];
                                        $isUncfg     = $alertDays === -1;
                                        $isNoData    = $alertDays === -2;
                                        $isExhausted = $alertDays === 0;
                                        $isCritical  = $alertDays >= 0 && $alertDays <= 1;
                                    @endphp
                                    <a href="{{ route('stocks.index', ['category' => 'conso']) }}" @class([
                                        'flex items-center justify-between p-4 rounded-2xl border transition-all hover:scale-[1.02] no-underline',
                                        'bg-purple-50 border-purple-200 text-purple-900' => $isUncfg || $isNoData,
                                        'bg-rose-50 border-rose-200 text-rose-900 animate-pulse' => !$isUncfg && !$isNoData && $isCritical,
                                        'bg-amber-50 border-amber-200 text-amber-900' => !$isUncfg && !$isNoData && !$isCritical,
                                    ])>
                                        <div class="flex items-center gap-3">
                                            <div @class(['w-8 h-8 rounded-xl flex items-center justify-center text-xs text-white',
                                                'bg-purple-600' => $isUncfg || $isNoData,
                                                'bg-rose-600' => !$isUncfg && !$isNoData && $isCritical,
                                                'bg-amber-500' => !$isUncfg && !$isNoData && !$isCritical])>
                                                <i class="fa-solid {{ ($isUncfg || $isNoData) ? 'fa-question' : 'fa-triangle-exclamation' }}"></i>
                                            </div>
                                            <div>
                                                <h5 class="text-[11px] font-black uppercase leading-none truncate">{{ str_replace([' (Poussin)',' (Poulette)',' (Pic de ponte)',' (Entretien)'], '', $alert['type']) }}</h5>
                                                <p class="text-[9px] opacity-70 uppercase font-black mt-1">
                                                    @if($isUncfg) {{ __("Article non configuré") }}
                                                    @elseif($isNoData) {{ __("Sorties non enregistrées") }}
                                                    @else {{ __("Silo actif") }}
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xs font-black uppercase tracking-tight leading-none">
                                                @if($isUncfg) {{ __('MANQUANT') }}
                                                @elseif($isNoData) {{ __('VÉRIFIER') }}
                                                @elseif($isExhausted) {{ __('ÉPUISÉ') }}
                                                @else {{ $alertDays }} {{ __('Jours') }}
                                                @endif
                                            </p>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <div class="p-6 bg-slate-50 rounded-2xl border border-dashed border-slate-200 text-center flex flex-col items-center gap-2">
                                <i class="fa-solid fa-circle-check text-emerald-500 text-lg"></i>
                                <p class="text-[9px] text-slate-500 uppercase tracking-wider">{{ __("Tous les silos disposent d'une autonomie supérieure au seuil.") }}</p>
                            </div>
                        @endif
                    </div>

                    {{-- BLOC B : ALERTES SANITAIRES --}}
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm flex flex-col justify-between">
                        <div>
                            <h4 class="text-[10px] font-black uppercase text-slate-400 tracking-wider flex items-center gap-2 mb-4">
                                <i class="fa-solid fa-shield-virus text-blue-500"></i> {{ __("Protocoles de Biossécurité") }}
                            </h4>
                            
                            <div class="space-y-3">
                                {{-- LOGIQUE 1 — URGENCE SANITAIRE --}}
                                @if(($emergencyBatches ?? collect())->count() > 0)
                                    <div class="bg-red-50 rounded-2xl border border-red-200 overflow-hidden">
                                        <div class="px-4 py-3 bg-red-100 flex items-center justify-between">
                                            <span class="text-[9px] font-black text-red-700 uppercase tracking-widest flex items-center gap-2">
                                                <i class="fa-solid fa-exclamation-triangle"></i> {{ __("Pic de Mortalité (24h)") }}
                                            </span>
                                            <span class="text-[8px] font-black bg-red-600 text-white px-2 py-0.5 rounded">{{ $emergencyBatches->count() }}</span>
                                        </div>
                                        <div class="p-3 space-y-2">
                                            @foreach($emergencyBatches->take(3) as $batch)
                                                <a href="{{ route('batches.show', $batch->id) }}" 
                                                   class="flex items-center justify-between p-3 bg-white rounded-xl border border-red-100 hover:border-red-300 transition-all no-underline group">
                                                    <span class="text-[10px] font-black text-slate-800 uppercase">{{ $batch->code }}</span>
                                                    <span class="text-[8px] font-black text-red-500 uppercase group-hover:text-red-700">{{ __("Inspecter") }} <i class="fa-solid fa-arrow-right ml-1"></i></span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- LOGIQUE 2 — DÉRIVE TECHNIQUE --}}
                                @if(($underperformingBatches ?? collect())->count() > 0)
                                    <div class="bg-amber-50 rounded-2xl border border-amber-200 overflow-hidden">
                                        <div class="px-4 py-3 bg-amber-100 flex items-center justify-between">
                                            <span class="text-[9px] font-black text-amber-700 uppercase tracking-widest flex items-center gap-2">
                                                <i class="fa-solid fa-chart-line"></i> {{ __("Dérive Technique") }}
                                            </span>
                                            <span class="text-[8px] font-black bg-amber-600 text-white px-2 py-0.5 rounded">{{ $underperformingBatches->count() }}</span>
                                        </div>
                                        <div class="p-3 space-y-2">
                                            @foreach($underperformingBatches->take(3) as $batch)
                                                <a href="{{ route('batches.show', $batch->id) }}" 
                                                   class="flex items-center justify-between p-3 bg-white rounded-xl border border-amber-100 hover:border-amber-300 transition-all no-underline group">
                                                    <span class="text-[10px] font-black text-slate-800 uppercase">{{ $batch->code }}</span>
                                                    <span class="text-[8px] font-black text-amber-600 uppercase">{{ __("Cumul > 5%") }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- LOGIQUE 3 — PROPHYLAXIE EN RETARD --}}
                                @if(($vaccineAlerts ?? collect())->count() > 0)
                                    <div class="bg-violet-50 rounded-2xl border border-violet-200 overflow-hidden">
                                        <div class="px-4 py-3 bg-violet-100 flex items-center justify-between">
                                            <span class="text-[9px] font-black text-violet-700 uppercase tracking-widest flex items-center gap-2">
                                                <i class="fa-solid fa-syringe"></i> {{ __("Prophylaxie en Retard") }}
                                            </span>
                                            <span class="text-[8px] font-black bg-violet-600 text-white px-2 py-0.5 rounded">{{ $vaccineAlerts->count() }}</span>
                                        </div>
                                        <div class="p-3 space-y-2">
                                            @foreach($vaccineAlerts->take(3) as $va)
                                                <a href="{{ route('batches.show', $va['batch']->id) }}"
                                                   class="flex items-center justify-between p-3 bg-white rounded-xl border border-violet-100 hover:border-violet-300 transition-all no-underline group">
                                                    <span class="text-[10px] font-black text-slate-800 uppercase">{{ $va['batch']->code }}</span>
                                                    <span class="text-[8px] font-black text-violet-600 uppercase truncate ml-2">{{ $va['count'] }} {{ __("acte(s)") }} · {{ \Illuminate\Support\Str::limit($va['next'], 18) }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- LOGIQUE 4 — BIEN-ÊTRE ANIMAL (boiterie / picage) --}}
                                @if(($welfareAlerts ?? collect())->count() > 0)
                                    <div class="bg-fuchsia-50 rounded-2xl border border-fuchsia-200 overflow-hidden">
                                        <div class="px-4 py-3 bg-fuchsia-100 flex items-center justify-between">
                                            <span class="text-[9px] font-black text-fuchsia-700 uppercase tracking-widest flex items-center gap-2">
                                                <i class="fa-solid fa-feather"></i> {{ __("Bien-être Animal") }}
                                            </span>
                                            <span class="text-[8px] font-black bg-fuchsia-600 text-white px-2 py-0.5 rounded">{{ $welfareAlerts->count() }}</span>
                                        </div>
                                        <div class="p-3 space-y-2">
                                            @foreach($welfareAlerts->take(3) as $wa)
                                                <a href="{{ route('batches.show', $wa['batch']->id) }}"
                                                   class="flex items-center justify-between p-3 bg-white rounded-xl border border-fuchsia-100 hover:border-fuchsia-300 transition-all no-underline group">
                                                    <span class="text-[10px] font-black text-slate-800 uppercase">{{ $wa['batch']->code }}</span>
                                                    <span class="text-[8px] font-black text-fuchsia-600 uppercase ml-2">
                                                        @foreach($wa['issues'] as $issue){{ $issue['type'] }} {{ $issue['pct'] }}%@if(!$loop->last) · @endif @endforeach
                                                    </span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- ALERTE VIDE SANITAIRE --}}
                                @if(($sanitaryAlertsCount ?? 0) > 0)
                                    <div class="bg-slate-900 text-white p-4 rounded-2xl flex items-center justify-between border-l-4 border-blue-500">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-xl bg-blue-500 text-white flex items-center justify-center shrink-0">
                                                <i class="fa-solid fa-soap"></i>
                                            </div>
                                            <div>
                                                <p class="text-xs font-black leading-none">{{ $sanitaryAlertsCount }} {{ __("Bâtiment(s)") }}</p>
                                                <p class="text-[8px] text-blue-400 mt-1 uppercase">{{ __("Vide sanitaire dépassé") }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- TOUT VA BIEN --}}
                                @if(($emergencyBatches ?? collect())->isEmpty() && ($underperformingBatches ?? collect())->isEmpty() && ($vaccineAlerts ?? collect())->isEmpty() && ($welfareAlerts ?? collect())->isEmpty() && ($sanitaryAlertsCount ?? 0) == 0)
                                    <div class="p-6 bg-slate-50 rounded-2xl border border-dashed border-slate-200 text-center flex flex-col items-center gap-2 h-full justify-center">
                                        <i class="fa-solid fa-heart-pulse text-emerald-500 text-lg"></i>
                                        <p class="text-[9px] text-slate-500 uppercase tracking-wider">{{ __("Statut Sanitaire RAS") }}</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- ALERTE STOCK SOUS SEUIL — tout article passé sous alert_threshold --}}
            @if(($lowStocks ?? collect())->isNotEmpty() && dashboard_block_visible('low_stock'))
            <div class="mb-10 bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-[10px] font-black uppercase text-slate-400 tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-boxes-stacked text-rose-500"></i> {{ __("Stocks Sous le Seuil de Réapprovisionnement") }}
                    </h4>
                    <span class="text-[8px] font-black bg-rose-600 text-white px-2 py-1 rounded-md uppercase">{{ $lowStocks->count() }}</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($lowStocks->take(9) as $s)
                        @php $ratio = $s->alert_threshold > 0 ? ($s->current_quantity / $s->alert_threshold) * 100 : 0; @endphp
                        <a href="{{ route('stocks.index', ['category' => $s->category]) }}" @class([
                            'flex items-center justify-between p-4 rounded-2xl border transition-all hover:scale-[1.02] no-underline',
                            'bg-rose-50 border-rose-200 text-rose-900 animate-pulse' => $s->current_quantity <= 0,
                            'bg-amber-50 border-amber-200 text-amber-900' => $s->current_quantity > 0,
                        ])>
                            <div class="min-w-0">
                                <h5 class="text-[11px] font-black uppercase leading-none truncate">{{ $s->item_name }}</h5>
                                <p class="text-[9px] opacity-70 uppercase font-black mt-1">{{ __("Seuil") }} : {{ number_format($s->alert_threshold, 0) }} {{ $s->unit }}</p>
                            </div>
                            <div class="text-right shrink-0 ml-2">
                                <p class="text-xs font-black uppercase tracking-tight leading-none">{{ number_format($s->current_quantity, 0) }} {{ $s->unit }}</p>
                                <p class="text-[8px] font-black opacity-60 mt-1">{{ number_format($ratio, 0) }}%</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- ALERTE PÉREMPTION — consommables périmés ou périmant bientôt --}}
            @if(($expiringStocks ?? collect())->isNotEmpty() && dashboard_block_visible('stock_expiry'))
            <div class="mb-10 bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-[10px] font-black uppercase text-slate-400 tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-hourglass-end text-rose-500"></i> {{ __("Péremption des Consommables") }}
                    </h4>
                    <span class="text-[8px] font-black bg-rose-600 text-white px-2 py-1 rounded-md uppercase">{{ $expiringStocks->count() }}</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($expiringStocks->take(9) as $s)
                        @php $left = $s->days_until_expiry; @endphp
                        <a href="{{ route('stocks.index', ['category' => $s->category]) }}" @class([
                            'flex items-center justify-between p-4 rounded-2xl border transition-all hover:scale-[1.02] no-underline',
                            'bg-rose-50 border-rose-200 text-rose-900 animate-pulse' => $left < 0,
                            'bg-amber-50 border-amber-200 text-amber-900' => $left >= 0,
                        ])>
                            <div class="min-w-0">
                                <h5 class="text-[11px] font-black uppercase leading-none truncate">{{ $s->item_name }}</h5>
                                <p class="text-[9px] opacity-70 uppercase font-black mt-1">
                                    {{ $s->expiry_date?->format('d/m/Y') }}{{ $s->lot_number ? ' · '.__('Lot').' '.$s->lot_number : '' }}
                                </p>
                            </div>
                            <div class="text-right shrink-0 ml-2">
                                <p class="text-xs font-black uppercase tracking-tight leading-none">{{ $left < 0 ? __('Périmé') : $left.' j' }}</p>
                                <p class="text-[8px] font-black opacity-60 mt-1">{{ number_format($s->current_quantity, 0) }} {{ $s->unit }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- ALERTE QUALITÉ EAU — visible uniquement si alertes actives --}}
            @if(($waterAlerts ?? collect())->isNotEmpty())
            <div class="bg-blue-950 rounded-[2.5rem] p-6 border border-blue-800">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-[10px] font-black uppercase text-blue-300 tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-droplet text-blue-400"></i> {{ __("Alertes Qualité Eau — Pisciculture") }}
                    </h4>
                    <span class="text-[8px] bg-blue-800 text-blue-200 px-2 py-1 rounded-md font-black uppercase">
                        {{ $waterAlerts->count() }} {{ __("Bassin(s)") }}
                    </span>
                </div>
                <div class="space-y-3">
                    @foreach($waterAlerts as $wa)
                    <a href="{{ route('batches.show', $wa['batch']->id) }}" class="block no-underline">
                        <div @class(['p-4 rounded-2xl border transition-all hover:scale-[1.01]',
                            'bg-red-900 border-red-700' => $wa['has_critical'],
                            'bg-amber-900 border-amber-700' => !$wa['has_critical']])>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[10px] font-black text-white uppercase">{{ $wa['batch']->code }}</span>
                                <span class="text-[8px] text-white/60 uppercase font-black">{{ $wa['batch']->building?->name ?? '—' }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach($wa['alerts'] as $alert)
                                <span @class(['text-[8px] font-black uppercase px-2 py-0.5 rounded-lg',
                                    'bg-red-700 text-white' => $alert['level'] === 'critical',
                                    'bg-amber-700 text-white' => $alert['level'] === 'warning'])>
                                    {{ $alert['metric'] }}: {{ $alert['value'] }}
                                </span>
                                @endforeach
                            </div>
                        </div>
                    </a>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- WIDGET CAMPAGNE TABASKI — visible uniquement si une campagne Tabaski est active --}}
            @if($tabaskiWidget ?? false)
            <div @class([
                'mb-8 rounded-[2rem] border-2 p-6 flex flex-col md:flex-row items-center justify-between gap-6 shadow-lg transition-all',
                'bg-gradient-to-r from-emerald-900 to-emerald-800 border-emerald-600 animate-pulse' => $tabaskiWidget['critical'],
                'bg-gradient-to-r from-amber-900 to-amber-800 border-amber-500' => !$tabaskiWidget['critical'] && $tabaskiWidget['urgent'],
                'bg-gradient-to-r from-slate-800 to-slate-900 border-slate-600' => !$tabaskiWidget['urgent'],
            ])>
                <div class="flex items-center gap-5">
                    <div @class([
                        'w-16 h-16 rounded-[1.5rem] flex items-center justify-center text-3xl shadow-2xl',
                        'bg-emerald-600' => $tabaskiWidget['critical'],
                        'bg-amber-600' => !$tabaskiWidget['critical'] && $tabaskiWidget['urgent'],
                        'bg-slate-700' => !$tabaskiWidget['urgent'],
                    ])>🐑</div>
                    <div class="text-white">
                        <p class="text-[8px] font-black uppercase tracking-[0.3em] opacity-60 mb-1">{{ __("Compte à rebours Tabaski") }}</p>
                        <p class="text-3xl font-black italic tracking-tighter leading-none">
                            @if($tabaskiWidget['days'] == 0)
                                {{ __("AUJOURD'HUI !") }}
                            @elseif($tabaskiWidget['days'] < 0)
                                {{ __("J +") }}{{ abs($tabaskiWidget['days']) }}
                            @else
                                {{ __("J —") }} {{ $tabaskiWidget['days'] }}
                            @endif
                        </p>
                        <p class="text-[9px] opacity-50 mt-1 uppercase font-black">{{ __("Eid al-Adha") }} · {{ $tabaskiWidget['date'] }}</p>
                    </div>
                </div>
                <div class="flex gap-6 text-white text-center">
                    <div>
                        <p class="text-2xl font-black italic">{{ number_format($tabaskiWidget['head_count']) }}</p>
                        <p class="text-[7px] font-black uppercase opacity-50 tracking-widest mt-1">{{ __("Têtes Prêtes") }}</p>
                    </div>
                    <div class="w-px bg-white/20"></div>
                    <div>
                        <p class="text-2xl font-black italic">{{ $tabaskiWidget['batches'] }}</p>
                        <p class="text-[7px] font-black uppercase opacity-50 tracking-widest mt-1">{{ __("Lots Actifs") }}</p>
                    </div>
                    <div class="w-px bg-white/20"></div>
                    <div>
                        <a href="{{ route('campaigns.show', $tabaskiWidget['campaign_id']) }}" class="text-[8px] font-black uppercase text-white/60 hover:text-white no-underline tracking-widest">
                            {{ __("Piloter la campagne") }} <i class="fa-solid fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            @endif

            {{-- PRODUCTION VÉGÉTALE (ferme intégrée) --}}
            @if($plantProduction)
            <a href="{{ route('cultures.dashboard') }}" class="block bg-white border border-slate-100 rounded-[3rem] shadow-sm p-6 mb-10 no-underline hover:border-green-200 transition group">
                <div class="flex items-center justify-between mb-5">
                    <span class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic"><i class="fa-solid fa-seedling text-green-600 mr-2"></i>{{ __("Production Végétale") }}</span>
                    @if($plantProduction['due_soon'] > 0)
                        <span class="text-[8px] font-black uppercase bg-amber-100 text-amber-700 px-3 py-1 rounded-full italic"><i class="fa-solid fa-calendar-day mr-1"></i>{{ $plantProduction['due_soon'] }} {{ __("récolte(s) sous 7j") }}</span>
                    @endif
                    <span class="text-[8px] font-black uppercase text-green-600 group-hover:translate-x-1 transition italic">{{ __("Ouvrir") }} <i class="fa-solid fa-arrow-right ml-1"></i></span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <div>
                        <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-1">{{ __("Cycles actifs") }}</p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ $plantProduction['cycles_active'] }}</p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-1">{{ __("Surface cultivée") }}</p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ number_format($plantProduction['area_cultivated'], 1, ',', ' ') }}<small class="text-[10px] opacity-40"> ha</small></p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-1">{{ __("Récolté (année)") }}</p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ number_format($plantProduction['harvest_ytd'], 0, ',', ' ') }}<small class="text-[10px] opacity-40"> kg</small></p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-1">{{ __("Transfo. (30j)") }}</p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ $plantProduction['transform_30d'] }}</p>
                    </div>
                </div>
            </a>
            @endif

            {{-- KPI ROW --}}
            @if(dashboard_block_visible('kpi_row'))
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-10">
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 italic">{{ __("Effectif Actif") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter italic">{{ number_format($totalBirdsCount) }}</p>
                    <p class="text-[8px] text-blue-600 mt-3 uppercase font-black">{{ __("Sujets en bâtiment") }}</p>
                </div>

                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-rose-500 uppercase tracking-widest mb-2 italic">{{ __("Mortalité Période") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter italic">{{ $mortalityRateDisplay }}%</p>
                    <div class="w-full bg-slate-50 h-2 rounded-full mt-4 overflow-hidden border border-slate-100">
                        <div class="bg-rose-600 h-full rounded-full" style="width: {{ min($globalMortalityRate ?? 0, 100) }}%"></div>
                    </div>
                </div>

                @if($showEggKpis ?? true)
                {{-- ENRICHI : Alerte de donnée manquante si pas d'oeufs aujourd'hui --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    @if(($totalEggsToday ?? 0) == 0)
                        <div class="absolute top-6 right-6 flex items-center gap-2 text-rose-500">
                            <span class="text-[7px] uppercase font-black tracking-widest hidden md:inline-block">{{ __("En attente de ramassage") }}</span>
                            <div class="w-3 h-3 bg-rose-500 rounded-full animate-ping shadow-lg shadow-rose-500/50"></div>
                        </div>
                    @endif
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic">{{ __("Ponte (HDP)") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter italic">{{ number_format($hdp, 1) }}%</p>
                    <p class="text-[8px] text-slate-400 mt-3 uppercase italic font-black">{{ __("Taux de ponte du jour") }}</p>
                </div>

                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative">
                    <p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mb-2 italic">{{ __("Stock Calibré") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter italic">
                        {{ number_format($totalEggsStock ?? 0, 1) }} <small class="text-xs">{{ __("Alv.") }}</small>
                    </p>
                    <p class="text-[8px] text-amber-600 mt-3 uppercase italic font-black">
                        {{ $totalBrokenToday ?? 0 }} {{ __("Cassés") }} <i class="fa-solid fa-heart-crack ml-1"></i>
                    </p>
                </div>
                @else
                {{-- KPI génériques pour les fermes sans suivi de ponte (ovins, poisson, lapins...) --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mb-2 italic">{{ __("Lots Actifs") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter italic">{{ number_format($activeLotsCount ?? 0) }}</p>
                    <p class="text-[8px] text-blue-600 mt-3 uppercase italic font-black">{{ __("Bandes en cours") }}</p>
                </div>

                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-cyan-500 uppercase tracking-widest mb-2 italic">{{ __("Bâtiments Occupés") }}</p>
                    <p class="text-4xl font-black text-slate-900 tracking-tighter italic">
                        {{ $occupiedBuildingsCount ?? 0 }}<small class="text-base text-slate-300">/{{ $totalBuildingsCount ?? 0 }}</small>
                    </p>
                    <p class="text-[8px] text-cyan-600 mt-3 uppercase italic font-black">{{ __("Occupation des sites") }}</p>
                </div>
                @endif
            </div>
            @endif

            {{-- PERFORMANCE TECHNIQUE (zootechnie) --}}
            @if(($technical['has_data'] ?? false) && dashboard_block_visible('technical'))
            <div class="mb-10">
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-5 px-2">
                    <span class="w-2 h-6 bg-indigo-600 rounded-full mr-3"></span> {{ __("Performance Technique") }}
                    <span class="ml-3 text-[8px] text-slate-300 normal-case tracking-normal font-bold">{{ __("Indicateurs zootechniques des lots actifs") }}</span>
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    {{-- Indice de consommation (FCR) --}}
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-indigo-500 uppercase tracking-widest mb-2 italic flex items-center gap-1">
                            {{ __("Indice Conso") }}
                            <i class="fa-solid fa-circle-info text-slate-200 cursor-help" title="{{ __('FCR : aliment consommé (kg) / biomasse vive produite (kg). Plus bas = plus efficace.') }}"></i>
                        </p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ $technical['fcr'] !== null ? number_format($technical['fcr'], 2) : '—' }}</p>
                        <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ __("kg alim / kg vif") }}</p>
                    </div>
                    {{-- GMQ --}}
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-blue-500 uppercase tracking-widest mb-2 italic">{{ __("GMQ moyen") }}</p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ $technical['gmq_g'] !== null ? number_format($technical['gmq_g']) : '—' }}<small class="text-xs ml-1 opacity-50">g/j</small></p>
                        <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ __("Gain quotidien") }}</p>
                    </div>
                    {{-- Viabilité --}}
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic">{{ __("Viabilité") }}</p>
                        <p class="text-3xl font-black {{ ($technical['viability'] ?? 100) >= 95 ? 'text-emerald-600' : (($technical['viability'] ?? 100) >= 90 ? 'text-amber-500' : 'text-rose-600') }} tracking-tighter italic">{{ number_format($technical['viability'], 1) }}<small class="text-xs ml-1 opacity-50">%</small></p>
                        <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ __("Taux de survie") }}</p>
                    </div>
                    {{-- Coût aliment / kg vif --}}
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-orange-500 uppercase tracking-widest mb-2 italic">{{ __("Coût alim / kg") }}</p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ $technical['feed_cost_per_kg'] !== null ? number_format($technical['feed_cost_per_kg'], 0, ',', ' ') : '—' }}</p>
                        <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ currency() }} / kg vif</p>
                    </div>
                    {{-- Prix de revient œuf --}}
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-2 italic flex items-center gap-1">
                            {{ __("Revient œuf") }}
                            <i class="fa-solid fa-circle-info text-slate-200 cursor-help" title="{{ __('Indicatif : (aliment + santé des lots de ponte ce mois) / œufs collectés.') }}"></i>
                        </p>
                        <p class="text-3xl font-black text-slate-900 tracking-tighter italic">{{ $technical['cost_per_egg'] !== null ? number_format($technical['cost_per_egg'], 0, ',', ' ') : '—' }}</p>
                        <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ currency() }} / œuf</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- Accès à la vue analytique consolidée (eau + énergie + mortalité) --}}
            <div class="mb-4 flex justify-end">
                <a href="{{ route('dashboard.analytics') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-900 text-white rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all no-underline shadow-lg italic">
                    <i class="fa-solid fa-magnifying-glass-chart"></i> {{ __("Vue analytique consolidée") }}
                </a>
            </div>

            {{-- TENDANCES 30 JOURS (graphiques Chart.js) --}}
            @if(dashboard_block_visible('trends'))
            <div class="mb-10 grid grid-cols-1 lg:grid-cols-3 gap-6"
                 x-data="dashboardTrends({{ Illuminate\Support\Js::from($trends ?? ['labels' => [], 'mortality' => [], 'eggs' => [], 'feed' => []]) }})">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-rose-500 uppercase tracking-widest italic mb-4">{{ __("Mortalité — 30 j") }}</p>
                    {{-- Conteneur à hauteur fixe + relative : indispensable avec
                         maintainAspectRatio:false, sinon le canvas grandit à l'infini. --}}
                    <div class="relative h-40"><canvas x-ref="mortalityChart"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest italic mb-4">{{ __("Ponte — 30 j") }}</p>
                    <div class="relative h-40"><canvas x-ref="eggsChart"></canvas></div>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-orange-500 uppercase tracking-widest italic mb-4">{{ __("Aliment (kg) — 30 j") }}</p>
                    <div class="relative h-40"><canvas x-ref="feedChart"></canvas></div>
                </div>
            </div>
            @endif

            {{-- SYNTHÈSE FINANCIÈRE DU MOIS (droits commerce) --}}
            @can('commerce.L')
            @if(!empty($financial) && dashboard_block_visible('financial'))
            <div class="mb-10 bg-slate-900 rounded-[2.5rem] p-7 shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-[11px] font-black uppercase text-white tracking-[0.2em] italic flex items-center">
                        <span class="w-2 h-6 bg-emerald-500 rounded-full mr-3"></span> {{ __("Synthèse Financière") }}
                        <span class="ml-3 text-[8px] text-slate-500 normal-case tracking-normal font-bold">{{ now()->translatedFormat('F Y') }}</span>
                    </h3>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-slate-800/60 rounded-2xl p-5">
                        <p class="text-[8px] font-black text-blue-300 uppercase tracking-widest italic mb-2">{{ __("Chiffre d'affaires") }}</p>
                        <p class="text-xl font-black text-white tracking-tighter italic">{{ number_format($financial['ca_total'], 0, ',', ' ') }}<small class="text-[9px] ml-1 opacity-40">{{ currency() }}</small></p>
                        <p class="text-[8px] text-slate-500 mt-1 uppercase font-black">{{ number_format($financial['ca_ventes'], 0, ',', ' ') }} ventes @if($financial['ca_lait'] > 0)· {{ number_format($financial['ca_lait'], 0, ',', ' ') }} lait @endif</p>
                    </div>
                    <div class="bg-slate-800/60 rounded-2xl p-5">
                        <p class="text-[8px] font-black text-rose-300 uppercase tracking-widest italic mb-2">{{ __("Charges totales") }}</p>
                        <p class="text-xl font-black text-white tracking-tighter italic">{{ number_format($financial['cost_total'], 0, ',', ' ') }}<small class="text-[9px] ml-1 opacity-40">{{ currency() }}</small></p>
                        <p class="text-[8px] text-slate-500 mt-1 uppercase font-black">{{ __("Alim + santé + dépenses") }}</p>
                    </div>
                    <div class="bg-slate-800/60 rounded-2xl p-5 border-l-4 {{ $financial['net_margin'] >= 0 ? 'border-emerald-500' : 'border-rose-500' }}">
                        <p class="text-[8px] font-black text-emerald-300 uppercase tracking-widest italic mb-2">{{ __("Marge nette") }}</p>
                        <p class="text-xl font-black {{ $financial['net_margin'] >= 0 ? 'text-emerald-400' : 'text-rose-400' }} tracking-tighter italic">{{ number_format($financial['net_margin'], 0, ',', ' ') }}<small class="text-[9px] ml-1 opacity-40">{{ currency() }}</small></p>
                        <p class="text-[8px] text-slate-500 mt-1 uppercase font-black">{{ $financial['ca_total'] > 0 ? number_format($financial['net_margin'] / $financial['ca_total'] * 100, 1) : 0 }}% {{ __("du CA") }}</p>
                    </div>
                    <div class="bg-slate-800/60 rounded-2xl p-5">
                        <p class="text-[8px] font-black text-amber-300 uppercase tracking-widest italic mb-2">{{ __("Trésorerie due") }}</p>
                        <p class="text-xl font-black text-white tracking-tighter italic">{{ number_format($financial['receivables'], 0, ',', ' ') }}<small class="text-[9px] ml-1 opacity-40">{{ currency() }}</small></p>
                        <p class="text-[8px] text-slate-500 mt-1 uppercase font-black">{{ __("Encours clients") }}</p>
                    </div>
                </div>
                @if(!empty($financial['top_expenses']))
                <div class="border-t border-slate-800 pt-5">
                    <p class="text-[8px] font-black text-slate-500 uppercase tracking-widest italic mb-3">{{ __("Principales dépenses du mois") }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($financial['top_expenses'] as $exp)
                        <span class="text-[9px] font-black uppercase text-slate-300 bg-slate-800 rounded-xl px-3 py-2 border border-slate-700 italic">
                            {{ $exp['label'] }} <span class="text-amber-400 ml-1">{{ number_format($exp['amount'], 0, ',', ' ') }}</span>
                        </span>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
            @endif
            @endcan

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 text-left">
                {{-- SUIVI DES BANDES --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="flex justify-between items-center px-6">
                        <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center">
                            <span class="w-2 h-6 bg-blue-600 rounded-full mr-3"></span> {{ __("Bandes Actives") }}
                        </h3>
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                            <input type="text" id="batchSearch" placeholder="{{ __('RECHERCHE...') }}" class="bg-slate-100 border-none rounded-2xl pl-10 pr-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-inner w-56 outline-none">
                        </div>
                    </div>
                    
                    <div id="batchContainer" class="space-y-4">
                        @foreach($activeBatches ?? [] as $batch)
                            @php
                                $lastCheck = $batch->latestDailyCheck;
                                $lastWeight = $lastCheck?->avg_weight ?? $batch->avg_weight_start;
                            @endphp
                            <div class="batch-card" data-search="{{ strtolower($batch->code . ' ' . ($batch->building?->name ?? '')) }}">
                                <a href="{{ route('batches.show', $batch->id) }}" class="flex items-center justify-between bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all no-underline group">
                                    <div class="flex items-center gap-5">
                                        <div class="w-14 h-14 rounded-[1.5rem] bg-slate-50 flex items-center justify-center text-slate-400 group-hover:bg-slate-900 group-hover:text-white transition-all shadow-inner">
                                            <i class="fa-solid fa-feather-pointed text-xl"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-black text-slate-900 text-xl uppercase italic leading-none tracking-tighter">{{ $batch->code }}</h4>
                                            <p class="text-[9px] font-black text-slate-400 uppercase mt-2 italic tracking-widest leading-none">
                                                {{ $batch->building?->name }} <span class="mx-2 opacity-20">|</span> J{{ $batch->age }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex gap-10 items-center pr-6 text-right">
                                        <div>
                                            <p class="text-2xl font-black text-slate-900 italic tracking-tighter leading-none mb-1">{{ number_format($batch->current_quantity) }}</p>
                                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic">{{ __("Sujets") }}</p>
                                        </div>
                                        <div class="border-l border-slate-50 pl-10">
                                            <p class="text-2xl font-black text-emerald-600 italic tracking-tighter leading-none mb-1">{{ number_format($lastWeight, 3) }}</p>
                                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic">kg/sujet</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>

                    @if(method_exists($activeBatches, 'links'))
                        <div class="mt-6">{{ $activeBatches->links() }}</div>
                    @endif
                </div>

                {{-- SIDEBAR --}}
                <div class="space-y-8">
                    @if(($familyBreakdown ?? collect())->count() > 1)
                    <div>
                        <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] ml-6 mb-5 italic flex items-center">
                            <span class="w-2 h-6 bg-purple-500 rounded-full mr-3"></span> {{ __("Cheptel par Famille") }}
                        </h3>
                        <div class="bg-white p-8 rounded-[3.5rem] border border-slate-100 shadow-sm space-y-5">
                            @php $maxHead = $familyBreakdown->max('head_count') ?: 1; @endphp
                            @foreach($familyBreakdown as $fam)
                                @php $perc = ($fam['head_count'] / $maxHead) * 100; @endphp
                                <div class="group">
                                    <div class="flex justify-between items-center text-[10px] font-black uppercase italic mb-2 tracking-tighter">
                                        <span class="text-slate-600 flex items-center gap-2">
                                            <span class="text-base">{{ $fam['icon'] }}</span> {{ $fam['label'] }}
                                            <span class="text-slate-300 font-bold">({{ $fam['batches'] }} {{ $fam['batches'] > 1 ? __('lots') : __('lot') }})</span>
                                        </span>
                                        <span class="text-slate-900">{{ number_format($fam['head_count']) }}</span>
                                    </div>
                                    <div class="w-full bg-slate-50 h-2 rounded-full overflow-hidden border border-slate-50 p-0.5">
                                        <div class="h-full rounded-full transition-all shadow-sm bg-{{ $fam['color'] }}-500" style="width: {{ max($perc, 4) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div>
                        <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] ml-6 mb-5 italic flex items-center">
                            <span class="w-2 h-6 bg-emerald-500 rounded-full mr-3"></span> {{ __("Densités Bâtiments") }}
                        </h3>
                        <div class="bg-white p-8 rounded-[3.5rem] border border-slate-100 shadow-sm space-y-6">
                            @foreach($buildings ?? [] as $b)
                                @php
                                    $occ = $b->batches_count ?? 0;
                                    $totalLive = $b->batches?->where('status', 'Actif')->sum('current_quantity') ?? 0;
                                    $perc = ($totalLive / max($b->capacity, 1)) * 100;
                                @endphp
                                <div class="group">
                                    <div class="flex justify-between text-[10px] font-black uppercase italic mb-2 tracking-tighter">
                                        <span class="text-slate-600">{{ $b->name }}</span>
                                        <span class="{{ $perc >= 90 ? 'text-rose-600' : 'text-slate-900' }}">{{ round($perc) }}%</span>
                                    </div>
                                    <div class="w-full bg-slate-50 h-2 rounded-full overflow-hidden border border-slate-50 p-0.5">
                                        <div class="h-full rounded-full transition-all shadow-sm {{ $perc >= 90 ? 'bg-rose-600' : 'bg-blue-600' }}" style="width: {{ min($perc, 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Actions Rapides --}}
                    <div class="grid grid-cols-1 gap-3">
                        @can('elevage.C')
                        <a href="{{ route('batches.create') }}" class="bg-slate-900 p-5 rounded-3xl text-white hover:bg-blue-600 transition-all flex justify-between items-center italic shadow-lg no-underline">
                            <span class="text-[10px] font-black uppercase tracking-widest">{{ __("Nouvelle Bande") }}</span>
                            <i class="fa-solid fa-plus text-xs"></i>
                        </a>
                        @endcan                        
                        @canany(['logistique.L', 'provenderie.L'])
                        <div class="grid grid-cols-2 gap-3">
                            @can('logistique.L')
                            <a href="{{ route('stocks.index', ['category' => 'oeufs']) }}" class="bg-white p-5 rounded-3xl border border-slate-100 hover:border-emerald-500 text-center shadow-sm group transition-all no-underline">
                                <i class="fa-solid fa-egg text-emerald-500 mb-2 block group-hover:scale-110 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Magasin Oeufs") }}</span>
                            </a>
                            @endcan
                            @can('provenderie.L')
                            <a href="{{ route('production.index') }}" class="bg-white p-5 rounded-3xl border border-slate-100 hover:border-blue-500 text-center shadow-sm group transition-all no-underline">
                                <i class="fa-solid fa-industry text-blue-500 mb-2 block group-hover:rotate-12 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Provenderie") }}</span>
                            </a>
                            @endcan
                        </div>
                        @endcanany
                        @canany(['elevage.L', 'logistique.L'])
                        <div class="grid grid-cols-2 gap-3">
                            @can('elevage.L')
                            <a href="{{ route('batches.archives') }}" class="bg-white p-5 rounded-[2rem] border border-slate-100 hover:border-slate-400 transition-all text-center italic group shadow-sm no-underline">
                                <i class="fa-solid fa-box-archive text-slate-500 mb-2 block group-hover:rotate-12 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Archives") }}</span>
                            </a>
                            @endcan
                            @can('logistique.L')
                            <a href="{{ route('stocks.index', ['category' => 'conso']) }}" class="bg-white p-5 rounded-[2rem] border border-slate-100 hover:border-amber-500 transition-all text-center italic group shadow-sm no-underline">
                                <i class="fa-solid fa-boxes-stacked text-amber-500 mb-2 block group-hover:rotate-12 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Stocks") }}</span>
                            </a>
                            @endcan
                        </div>
                        @endcanany
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Chart.js (CDN) pour les graphiques de tendance — même approche que provenderie --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
    // Composant Alpine des graphiques de tendance (30 jours).
    document.addEventListener('alpine:init', () => {
        Alpine.data('dashboardTrends', (data) => ({
            init() {
                // Chart.js (CDN) peut charger après Alpine : on attend sa dispo.
                const ready = () => {
                    if (typeof Chart === 'undefined') { setTimeout(ready, 120); return; }
                    this.draw();
                };
                ready();
            },
            draw() {
                const base = (ref, color, fill, dataset, kind = 'line') => {
                    const el = this.$refs[ref];
                    if (!el) return;
                    new Chart(el.getContext('2d'), {
                        type: kind,
                        data: {
                            labels: data.labels,
                            datasets: [{
                                data: dataset,
                                borderColor: color,
                                backgroundColor: fill,
                                borderWidth: 2,
                                fill: kind === 'line',
                                tension: 0.35,
                                pointRadius: 0,
                                pointHoverRadius: 4,
                                borderRadius: kind === 'bar' ? 4 : 0,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false }, ticks: { maxTicksLimit: 6, font: { size: 8 }, color: '#94a3b8' } },
                                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { maxTicksLimit: 4, font: { size: 8 }, color: '#94a3b8' } },
                            },
                        },
                    });
                };
                base('mortalityChart', '#f43f5e', 'rgba(244,63,94,0.08)', data.mortality, 'bar');
                base('eggsChart', '#10b981', 'rgba(16,185,129,0.10)', data.eggs, 'line');
                base('feedChart', '#f97316', 'rgba(249,115,22,0.10)', data.feed, 'line');
            },
        }));
    });
    </script>

    <script>
    document.getElementById('batchSearch')?.addEventListener('input', function(e) {
        let filter = e.target.value.toLowerCase();
        document.querySelectorAll('.batch-card').forEach(card => {
            let text = card.getAttribute('data-search') || '';
            card.style.display = text.includes(filter) ? '' : 'none';
        });
    });

    // État vide explicite — affiché quand il n'y a réellement aucun lot actif.
    const emptyBatchesHtml = `
        <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm px-8 py-16 text-center">
            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-5 shadow-inner">
                <i class="fa-solid fa-feather-pointed text-2xl text-slate-200"></i>
            </div>
            <p class="text-slate-300 font-black uppercase text-[11px] tracking-[0.3em] italic">{{ __("Aucun lot actif") }}</p>
        </div>`;

    async function loadOfflineContent() {
        const container = document.getElementById('batchContainer');
        if (!container || container.children.length > 0) return;

        // EN LIGNE : le serveur fait foi. Conteneur vide = aucun lot actif réel.
        // On n'affiche PAS le miroir local (potentiellement périmé) — on montre
        // un état vide explicite plutôt que des cartes « MODE TERRAIN »
        // trompeuses (ex. lot virtuel d'œufs externes resté en cache).
        if (navigator.onLine) {
            container.innerHTML = emptyBatchesHtml;
            return;
        }

        if (typeof db === 'undefined') return;

        // HORS-LIGNE : on rejoue le miroir IndexedDB. Filtre défensif : on écarte
        // les lots virtuels (effectif nul, ou code « EXT-… » des œufs externes).
        const localBatches = (await db.batches.toArray()).filter(b =>
            Number(b.current_quantity) > 0 && !String(b.code || '').toUpperCase().startsWith('EXT-')
        );

        if (localBatches.length === 0) {
            container.innerHTML = '<p class="p-10 text-center text-slate-400 text-[10px] uppercase italic font-black">{{ __("Aucune donnée locale hors-ligne.") }}</p>';
            return;
        }

        container.innerHTML = localBatches.map(batch => `
            <div class="batch-card" data-search="${batch.code.toLowerCase()}">
                <div class="flex items-center justify-between bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm opacity-80">
                    <div class="flex items-center gap-5">
                        <div class="w-14 h-14 rounded-[1.5rem] bg-slate-900 text-white flex items-center justify-center">
                            <i class="fa-solid fa-plane-slash"></i>
                        </div>
                        <div>
                            <h4 class="font-black text-slate-900 text-xl uppercase italic leading-none tracking-tighter">${batch.code}</h4>
                            <p class="text-[9px] font-black text-blue-600 uppercase mt-2 italic">{{ __("MODE TERRAIN") }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-black text-slate-900 italic leading-none">${batch.current_quantity}</p>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Sujets") }}</p>
                    </div>
                </div>
            </div>
        `).join('');
    }

    window.addEventListener('load', loadOfflineContent);
    </script>
</x-app-layout>