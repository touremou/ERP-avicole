<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">🐐 {{ __("Collecte de lait") }}</h2>
            <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Laiterie caprine — suivi par lot & prix GNF") }}</p>
        </div>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            @if(session('success'))
                <div class="p-5 bg-emerald-50 text-emerald-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-emerald-200">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">{{ session('error') }}</div>
            @endif

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic">{{ __("Litres aujourd'hui") }}</p>
                    <p class="text-3xl font-black text-slate-900 italic tracking-tighter">{{ number_format($totalsToday->liters ?? 0, 1) }} <small class="text-sm">L</small></p>
                </div>
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mb-2 italic">{{ __("Valeur du jour") }}</p>
                    <p class="text-3xl font-black text-slate-900 italic tracking-tighter">{{ number_format($totalsToday->value ?? 0) }}</p>
                    <p class="text-[8px] text-amber-600 mt-2 uppercase font-black">GNF</p>
                </div>
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 italic">{{ __("Litres 30 jours") }}</p>
                    <p class="text-3xl font-black text-slate-900 italic tracking-tighter">{{ number_format($last30->liters ?? 0, 0) }} <small class="text-sm">L</small></p>
                </div>
                <div class="bg-slate-900 p-7 rounded-[2.5rem] text-white shadow-sm">
                    <p class="text-[9px] font-black text-emerald-400 uppercase tracking-widest mb-2 italic">{{ __("CA lait 30 jours") }}</p>
                    <p class="text-3xl font-black italic tracking-tighter">{{ number_format($last30->value ?? 0) }}</p>
                    <p class="text-[8px] opacity-60 mt-2 uppercase font-black">GNF</p>
                </div>
            </div>

            {{-- LOTS LAITIERS --}}
            <div>
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-5 ml-2">
                    <span class="w-2 h-6 bg-emerald-500 rounded-full mr-3"></span> {{ __("Lots laitiers actifs") }}
                </h3>

                @forelse($dairyBatches as $batch)
                    @php
                        $todayMilk = $batch->milkProductions->firstWhere('production_date', \Carbon\Carbon::today());
                        $lastMilk = $batch->milkProductions->first();
                        $milkTarget = (float) setting('elevage.lait_cible_chevre', 1.5);
                        $yieldPerFemale = $todayMilk?->yield_per_female;
                    @endphp
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-center gap-5">
                            <div class="w-14 h-14 rounded-[1.5rem] bg-emerald-50 flex items-center justify-center text-2xl shadow-inner">{{ $batch->species?->icon ?? '🐐' }}</div>
                            <div>
                                <a href="{{ route('batches.show', $batch->id) }}" class="font-black text-slate-900 text-lg uppercase italic no-underline hover:text-emerald-600 leading-none tracking-tighter">{{ $batch->code }}</a>
                                <p class="text-[9px] font-black text-slate-400 uppercase mt-2 tracking-widest">{{ $batch->species?->name_fr }} · {{ $batch->building?->name ?? '—' }} · {{ number_format($batch->current_quantity) }} {{ __("têtes") }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-6">
                            <div class="text-right">
                                <p class="text-xl font-black italic leading-none {{ $todayMilk ? 'text-emerald-600' : 'text-slate-300' }}">
                                    {{ $todayMilk ? number_format($todayMilk->total_liters, 1).' L' : '—' }}
                                </p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-1">{{ __("Collecté aujourd'hui") }}</p>
                                @if($yieldPerFemale !== null)
                                <p @class(['text-[8px] font-black uppercase tracking-widest mt-1',
                                    'text-emerald-500' => $yieldPerFemale >= $milkTarget,
                                    'text-amber-500'   => $yieldPerFemale < $milkTarget])>
                                    {{ number_format($yieldPerFemale, 2) }} {{ __("L/tête") }} <span class="opacity-50">/ {{ __("cible") }} {{ number_format($milkTarget, 1) }}</span>
                                </p>
                                @endif
                            </div>
                            @if($todayMilk)
                                @can('production.M')
                                <a href="{{ route('milk-productions.edit', $todayMilk) }}" class="bg-white border border-emerald-200 text-emerald-700 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-50 transition-all no-underline shadow-sm whitespace-nowrap">
                                    <i class="fa-solid fa-pen mr-1"></i> {{ __('Rectifier') }}
                                </a>
                                @endcan
                            @else
                                @can('production.C')
                                <a href="{{ route('milk-productions.create', ['batch_id' => $batch->id]) }}" class="bg-emerald-600 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-700 transition-all no-underline shadow-sm whitespace-nowrap">
                                    <i class="fa-solid fa-droplet mr-1"></i> {{ __('Saisir') }}
                                </a>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="bg-white p-16 rounded-[3rem] border border-slate-100 shadow-sm text-center">
                        <div class="text-5xl mb-4">🐐</div>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Aucun lot laitier actif. Créez un lot d'une espèce/type laitier (ex : chèvre laitière).") }}</p>
                    </div>
                @endforelse
            </div>

            {{-- COLLECTES RÉCENTES --}}
            @if($recentProds->isNotEmpty())
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic mb-6">{{ __("Collectes récentes") }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="pb-3">{{ __("Date") }}</th><th class="pb-3">{{ __("Lot") }}</th><th class="pb-3 text-center">{{ __("Matin") }}</th><th class="pb-3 text-center">{{ __("Soir") }}</th><th class="pb-3 text-center">{{ __("Total") }}</th><th class="pb-3 text-right">{{ __("PU (GNF)") }}</th><th class="pb-3 text-right">{{ __("Valeur") }}</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentProds as $m)
                            <tr class="text-xs font-black border-b border-slate-50">
                                <td class="py-3 text-slate-500">{{ $m->production_date->format('d/m/Y') }}</td>
                                <td class="py-3 text-slate-800 uppercase">{{ $m->batch?->code ?? '—' }}</td>
                                <td class="py-3 text-center text-slate-500">{{ number_format($m->morning_liters, 1) }}</td>
                                <td class="py-3 text-center text-slate-500">{{ number_format($m->evening_liters, 1) }}</td>
                                <td class="py-3 text-center text-emerald-600">{{ number_format($m->total_liters, 1) }} L</td>
                                <td class="py-3 text-right text-slate-500">{{ number_format($m->unit_price) }}</td>
                                <td class="py-3 text-right text-slate-900">{{ number_format($m->total_value) }}</td>
                                <td class="py-3 text-right">
                                    @can('production.M')
                                    <a href="{{ route('milk-productions.edit', $m) }}" class="text-slate-300 hover:text-emerald-600 no-underline"><i class="fa-solid fa-pen text-[10px]"></i></a>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
