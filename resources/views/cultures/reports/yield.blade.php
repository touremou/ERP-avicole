<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <x-page-header :title="__('Analyse des Rendements')" :subtitle="__('Production végétale') . ' · ' . $year" icon="fa-wheat-awn" accent="green">
            <x-slot name="actions">
                <a href="{{ route('crop-reports.yield.pdf', request()->query()) }}" class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 hover:bg-green-700 transition">
                    <i class="fa-solid fa-file-pdf"></i> {{ __("Export PDF") }}
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- FILTRES --}}
            <form method="GET" action="{{ route('crop-reports.yield') }}" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
                <div>
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">{{ __("Année") }}</label>
                    <select name="year" class="text-[11px] font-black rounded-xl border-slate-200 px-3 py-2">
                        @foreach($years as $y)
                            <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
                        @endforeach
                        @if($years->isEmpty())
                            <option value="{{ now()->year }}" selected>{{ now()->year }}</option>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest block mb-1">{{ __("Culture") }}</label>
                    <select name="crop_name" class="text-[11px] font-black rounded-xl border-slate-200 px-3 py-2">
                        <option value="">{{ __("Toutes") }}</option>
                        @foreach($cropNames as $cn)
                            <option value="{{ $cn }}" @selected($cn == $cropName)>{{ $cn }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest italic hover:bg-green-700 transition">
                    <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                </button>
            </form>

            {{-- KPI CARDS --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Cycles récoltés") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $cycles->count() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Surface totale") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($totalArea, 1, ',', ' ') }} <small class="text-[10px] opacity-40">ha</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Production totale") }}</p>
                    <p class="text-3xl font-black leading-none">{{ number_format($totalHarvested, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Rendement moyen") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($avgYieldPerHa, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg/ha</small></p>
                </div>
            </div>

            {{-- PAR CULTURE --}}
            @if($byCrop->isNotEmpty())
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 pt-8 pb-4">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Synthèse par culture") }}</h3>
                </div>
                <table class="w-full text-left text-[11px]">
                    <thead>
                        <tr class="border-t border-slate-50">
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Culture") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Cycles") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Surface (ha)") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Production (kg)") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Rdt kg/ha") }}</th>
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Marge nette") }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byCrop as $row)
                        <tr class="border-t border-slate-50 hover:bg-slate-50 transition">
                            <td class="px-8 py-4 font-black text-slate-800">{{ $row['name'] }}</td>
                            <td class="px-4 py-4 text-right text-slate-600">{{ $row['cycles_count'] }}</td>
                            <td class="px-4 py-4 text-right text-slate-600">{{ number_format($row['total_ha'], 2, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right font-black text-green-600">{{ number_format($row['total_kg'], 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right font-black text-slate-800">{{ number_format($row['yield_per_ha'], 0, ',', ' ') }}</td>
                            <td class="px-8 py-4 text-right font-black {{ $row['net_margin'] >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                {{ number_format($row['net_margin'], 0, ',', ' ') }} <span class="text-[8px] opacity-60">{{ $currency }}</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- DÉTAIL DES CYCLES --}}
            @if($cycles->isNotEmpty())
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 pt-8 pb-4">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Détail des cycles") }}</h3>
                </div>
                <table class="w-full text-left text-[11px]">
                    <thead>
                        <tr class="border-t border-slate-50">
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Cycle") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Parcelle") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Surface") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Récolte (kg)") }}</th>
                            <th class="px-4 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Rdt kg/ha") }}</th>
                            <th class="px-8 py-3 text-[8px] font-black uppercase text-slate-400 tracking-widest text-right">{{ __("Marge") }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cycles as $cycle)
                        <tr class="border-t border-slate-50 hover:bg-slate-50 transition">
                            <td class="px-8 py-3">
                                <a href="{{ route('crop-cycles.show', $cycle) }}" class="font-black text-slate-800 hover:text-green-700 no-underline">{{ $cycle->crop_name }}</a>
                                @if($cycle->variety)<span class="ml-1 text-[8px] text-slate-400">{{ $cycle->variety }}</span>@endif
                                <p class="text-[8px] text-slate-400 mt-0.5">{{ $cycle->planting_date?->format('d/m/Y') }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $cycle->plot->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ number_format((float)$cycle->area_used_ha, 2, ',', ' ') }} ha</td>
                            <td class="px-4 py-3 text-right font-black text-green-600">{{ number_format($cycle->total_harvested, 0, ',', ' ') }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-800">{{ number_format($cycle->yield_per_ha, 0, ',', ' ') }}</td>
                            <td class="px-8 py-3 text-right font-black {{ $cycle->net_margin >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                {{ number_format($cycle->net_margin, 0, ',', ' ') }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="bg-white p-12 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                <i class="fa-solid fa-seedling text-4xl text-slate-200 mb-4"></i>
                <p class="text-[11px] font-black uppercase text-slate-400 tracking-widest">{{ __("Aucun cycle récolté pour cette sélection") }}</p>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
