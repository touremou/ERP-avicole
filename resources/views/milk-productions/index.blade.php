<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🐐 Collecte de lait')" :subtitle="__('Laiterie caprine — suivi par lot & prix') . ' ' . currency()" icon="fa-bottle-droplet" accent="cyan" />
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <x-flash />

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <x-stat-tile :label="__('Litres aujourd\'hui')" :value="number_format($totalsToday->liters ?? 0, 1)" unit="L" accent="cyan" />
                <x-stat-tile :label="__('Valeur du jour')" :value="number_format($totalsToday->value ?? 0)" :sub="currency()" accent="amber" />
                <x-stat-tile :label="__('Litres 30 jours')" :value="number_format($last30->liters ?? 0, 0)" unit="L" accent="slate" />
                <x-stat-tile :label="__('CA lait 30 jours')" :value="number_format($last30->value ?? 0)" :sub="currency()" accent="cyan" :dark="true" />
            </div>

            {{-- LOTS LAITIERS --}}
            <div>
                <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center mb-5 ml-2">
                    <span class="w-2 h-6 bg-cyan-500 rounded-full mr-3"></span> {{ __("Lots laitiers actifs") }}
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
                            <div class="w-14 h-14 rounded-[1.5rem] bg-cyan-50 flex items-center justify-center text-2xl shadow-inner">{{ $batch->species?->icon ?? '🐐' }}</div>
                            <div>
                                <a href="{{ route('batches.show', $batch->id) }}" class="font-black text-slate-900 text-lg uppercase italic no-underline hover:text-cyan-600 leading-none tracking-tighter">{{ $batch->code }}</a>
                                <p class="text-[9px] font-black text-slate-400 uppercase mt-2 tracking-widest">{{ $batch->species?->name_fr }} · {{ $batch->building?->name ?? '—' }} · {{ number_format($batch->current_quantity) }} {{ __("têtes") }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-6">
                            <div class="text-right">
                                <p class="text-xl font-black italic leading-none {{ $todayMilk ? 'text-cyan-600' : 'text-slate-300' }}">
                                    {{ $todayMilk ? number_format($todayMilk->total_liters, 1).' L' : '—' }}
                                </p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-1">{{ __("Collecté aujourd'hui") }}</p>
                                @if($yieldPerFemale !== null)
                                <p @class(['text-[8px] font-black uppercase tracking-widest mt-1',
                                    'text-cyan-500' => $yieldPerFemale >= $milkTarget,
                                    'text-amber-500'   => $yieldPerFemale < $milkTarget])>
                                    {{ number_format($yieldPerFemale, 2) }} {{ __("L/tête") }} <span class="opacity-50">/ {{ __("cible") }} {{ number_format($milkTarget, 1) }}</span>
                                </p>
                                @endif
                            </div>
                            @if($todayMilk)
                                @can('production.M')
                                <a href="{{ route('milk-productions.edit', $todayMilk) }}" class="bg-white border border-cyan-200 text-cyan-700 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-cyan-50 transition-all no-underline shadow-sm whitespace-nowrap">
                                    <i class="fa-solid fa-pen mr-1"></i> {{ __('Rectifier') }}
                                </a>
                                @endcan
                            @else
                                @can('production.C')
                                <a href="{{ route('milk-productions.create', ['batch_id' => $batch->id]) }}" class="bg-cyan-600 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-cyan-700 transition-all no-underline shadow-sm whitespace-nowrap">
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
                                <th class="pb-3">{{ __("Date") }}</th><th class="pb-3">{{ __("Lot") }}</th><th class="pb-3 text-center">{{ __("Matin") }}</th><th class="pb-3 text-center">{{ __("Soir") }}</th><th class="pb-3 text-center">{{ __("Total") }}</th><th class="pb-3 text-right">{{ __("PU") }} ({{ currency() }})</th><th class="pb-3 text-right">{{ __("Valeur") }}</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentProds as $m)
                            <tr class="text-xs font-black border-b border-slate-50">
                                <td class="py-3 text-slate-500">{{ $m->production_date->format('d/m/Y') }}</td>
                                <td class="py-3 text-slate-800 uppercase">{{ $m->batch?->code ?? '—' }}</td>
                                <td class="py-3 text-center text-slate-500">{{ number_format($m->morning_liters, 1) }}</td>
                                <td class="py-3 text-center text-slate-500">{{ number_format($m->evening_liters, 1) }}</td>
                                <td class="py-3 text-center text-cyan-600">{{ number_format($m->total_liters, 1) }} L</td>
                                <td class="py-3 text-right text-slate-500">{{ number_format($m->unit_price) }}</td>
                                <td class="py-3 text-right text-slate-900">{{ number_format($m->total_value) }}</td>
                                <td class="py-3 text-right">
                                    @can('production.M')
                                    <a href="{{ route('milk-productions.edit', $m) }}" class="text-slate-300 hover:text-cyan-600 no-underline"><i class="fa-solid fa-pen text-[10px]"></i></a>
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
