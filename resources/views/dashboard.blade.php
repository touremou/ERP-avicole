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
                {{-- ENRICHI : Info bulle CMUP --}}
                <div class="bg-white px-6 py-4 rounded-[1.5rem] border border-slate-100 text-right shadow-sm group">
                    <p class="text-[8px] font-black text-slate-400 uppercase italic mb-1 flex items-center justify-end gap-1.5">
                        {{ __("Valeur Mat. Premières") }}
                        <i class="fa-solid fa-circle-info text-slate-300 group-hover:text-blue-500 transition-colors cursor-help" title="{{ __('Valorisation basée sur le Coût Moyen Unitaire Pondéré (CMUP) des derniers achats') }}"></i>
                    </p>
                    <p class="text-base font-black text-slate-900 leading-none">{{ number_format($rawMaterialsValue ?? 0, 0, ',', ' ') }} <small class="text-[9px] opacity-40">GNF</small></p>
                </div>
                
                {{-- ENRICHI : Info bulle Marge + Changement de label --}}
                <div class="bg-slate-900 px-6 py-4 rounded-[1.5rem] text-right shadow-2xl border-l-4 border-emerald-500 group">
                    <p class="text-[8px] font-black text-emerald-400 uppercase italic mb-1 flex items-center justify-end gap-1.5">
                        {{ __("Marge Nette Mensuelle") }}
                        <i class="fa-solid fa-circle-info text-slate-600 group-hover:text-emerald-300 transition-colors cursor-help" title="{{ __('CA du mois en cours (sorties magasin) - Coûts réels (Aliment + Santé)') }}"></i>
                    </p>
                    <p class="text-base font-black text-white leading-none">{{ number_format($safeProfit ?? 0, 0, ',', ' ') }} <small class="text-[9px] opacity-40">GNF</small></p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            
            {{-- CENTRE DE CONTRÔLE DES ALERTES --}}
            <div class="mb-10 space-y-4">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    {{-- BLOC A : AUTONOMIE SILOS --}}
                    <div class="lg:col-span-2 bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-[10px] font-black uppercase text-slate-400 tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-wheat-awn text-amber-500"></i> {{ __("Autonomie des Silos") }}
                            </h4>
                            <span class="text-[8px] bg-slate-100 text-slate-600 px-2 py-1 rounded-md font-black uppercase">{{ __("Seuil : 3 jours") }}</span>
                        </div>
                        
                        @if(count($criticalTypes ?? []) > 0)
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                @foreach($criticalTypes as $alert)
                                    <a href="{{ route('stocks.index', ['category' => 'conso']) }}" @class([
                                        'flex items-center justify-between p-4 rounded-2xl border transition-all hover:scale-[1.02] no-underline',
                                        'bg-rose-50 border-rose-200 text-rose-900 animate-pulse' => $alert['days'] <= 1,
                                        'bg-amber-50 border-amber-200 text-amber-900' => $alert['days'] > 1
                                    ])>
                                        <div class="flex items-center gap-3">
                                            <div @class(['w-8 h-8 rounded-xl flex items-center justify-center text-xs text-white', 'bg-rose-600' => $alert['days'] <= 1, 'bg-amber-500' => $alert['days'] > 1])>
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                            </div>
                                            <div>
                                                <h5 class="text-[11px] font-black uppercase leading-none truncate">{{ str_replace([' (Poussin)',' (Poulette)',' (Pic de ponte)',' (Entretien)'], '', $alert['type']) }}</h5>
                                                <p class="text-[9px] opacity-70 uppercase font-black mt-1">{{ __("Silo actif") }}</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xs font-black uppercase tracking-tight leading-none">{{ $alert['days'] == 0 ? __('ÉPUISÉ') : $alert['days'].' '.__('Jours') }}</p>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <div class="p-6 bg-slate-50 rounded-2xl border border-dashed border-slate-200 text-center flex flex-col items-center gap-2">
                                <i class="fa-solid fa-circle-check text-emerald-500 text-lg"></i>
                                <p class="text-[9px] text-slate-500 uppercase tracking-wider">{{ __("Tous les silos disposent d'une autonomie > 3 jours.") }}</p>
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
                                @if(($emergencyBatches ?? collect())->isEmpty() && ($underperformingBatches ?? collect())->isEmpty() && ($sanitaryAlertsCount ?? 0) == 0)
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

            {{-- WIDGET TABASKI — visible uniquement si lots ovins actifs --}}
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
                        <a href="{{ route('campaigns.index') }}" class="text-[8px] font-black uppercase text-white/60 hover:text-white no-underline tracking-widest">
                            {{ __("Piloter la campagne") }} <i class="fa-solid fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            @endif

            {{-- KPI ROW --}}
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
                                $lastCheck = $batch->dailyChecks?->sortByDesc('check_date')->first();
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
                        <div class="grid grid-cols-2 gap-3">
                            <a href="{{ route('stocks.index', ['category' => 'oeufs']) }}" class="bg-white p-5 rounded-3xl border border-slate-100 hover:border-emerald-500 text-center shadow-sm group transition-all no-underline">
                                <i class="fa-solid fa-egg text-emerald-500 mb-2 block group-hover:scale-110 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Magasin Oeufs") }}</span>
                            </a>
                            <a href="{{ route('production.index') }}" class="bg-white p-5 rounded-3xl border border-slate-100 hover:border-blue-500 text-center shadow-sm group transition-all no-underline">
                                <i class="fa-solid fa-industry text-blue-500 mb-2 block group-hover:rotate-12 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Provenderie") }}</span>
                            </a>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <a href="{{ route('batches.archives') }}" class="bg-white p-5 rounded-[2rem] border border-slate-100 hover:border-slate-400 transition-all text-center italic group shadow-sm no-underline">
                                <i class="fa-solid fa-box-archive text-slate-500 mb-2 block group-hover:rotate-12 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Archives") }}</span>
                            </a>
                            <a href="{{ route('stocks.index', ['category' => 'conso']) }}" class="bg-white p-5 rounded-[2rem] border border-slate-100 hover:border-amber-500 transition-all text-center italic group shadow-sm no-underline">
                                <i class="fa-solid fa-boxes-stacked text-amber-500 mb-2 block group-hover:rotate-12 transition-transform"></i>
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ __("Stocks") }}</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('batchSearch')?.addEventListener('input', function(e) {
        let filter = e.target.value.toLowerCase();
        document.querySelectorAll('.batch-card').forEach(card => {
            let text = card.getAttribute('data-search') || '';
            card.style.display = text.includes(filter) ? '' : 'none';
        });
    });

    async function loadOfflineContent() {
        const container = document.getElementById('batchContainer');
        if (!container || container.children.length > 0) return; 
        if (typeof db === 'undefined') return;

        const localBatches = await db.batches.toArray();
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