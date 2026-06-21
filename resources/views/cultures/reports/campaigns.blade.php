<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-sky-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-calendar-days text-lg"></i>
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Bilan des Campagnes") }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ __("Production végétale") }} · {{ $year }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('crop-reports.campaigns.pdf', request()->query()) }}" class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 hover:bg-sky-600 transition">
                    <i class="fa-solid fa-file-pdf"></i> {{ __("Export PDF") }}
                </a>
                <a href="{{ route('crop-reports.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-1"></i> {{ __("Rapports") }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- FILTRE ANNÉE --}}
            <form method="GET" action="{{ route('crop-reports.campaigns') }}" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
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
                <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest italic hover:bg-sky-600 transition">
                    <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                </button>
            </form>

            @forelse($campaigns as $campaign)
                @php
                    $harvested   = $campaign->total_harvested;
                    $cyclesCount = $campaign->cycles->count();
                    $totalRevenue = (float) $campaign->cycles->sum('total_revenue');
                    $totalMargin  = (float) $campaign->cycles->sum(fn ($c) => $c->net_margin);
                    $progress    = $campaign->progress_percent;
                @endphp
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="p-8">
                        <div class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900 uppercase italic tracking-tighter leading-none">{{ $campaign->name }}</h3>
                                <p class="text-[9px] font-bold text-{{ $campaign->season_color }}-500 uppercase tracking-widest mt-1 italic">
                                    {{ $campaign->season_label }} · {{ $campaign->status_label }}
                                </p>
                            </div>
                            <a href="{{ route('crop-campaigns.show', $campaign) }}" class="text-[9px] font-black uppercase text-sky-600 hover:text-sky-800 no-underline">
                                {{ __("Détails") }} <i class="fa-solid fa-arrow-right ml-1 text-[8px]"></i>
                            </a>
                        </div>

                        @if($progress !== null)
                        <div class="mb-6">
                            <div class="flex justify-between text-[9px] mb-1">
                                <span class="font-black text-slate-500 uppercase tracking-widest">{{ __("Objectif") }} : {{ number_format((float)$campaign->target_production_t, 1, ',', ' ') }} t</span>
                                <span class="font-black text-slate-700">{{ $progress }}%</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-3">
                                <div class="bg-{{ $campaign->season_color }}-500 h-3 rounded-full transition-all" style="width: {{ $progress }}%"></div>
                            </div>
                        </div>
                        @endif

                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="bg-slate-50 p-5 rounded-[1.5rem]">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-1">{{ __("Cycles") }}</p>
                                <p class="text-2xl font-black text-slate-900 leading-none">{{ $cyclesCount }}</p>
                            </div>
                            <div class="bg-slate-50 p-5 rounded-[1.5rem]">
                                <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-1">{{ __("Récolté") }}</p>
                                <p class="text-2xl font-black text-green-600 leading-none">{{ number_format($harvested, 0, ',', ' ') }} <small class="text-[9px] opacity-40">kg</small></p>
                            </div>
                            <div class="bg-slate-50 p-5 rounded-[1.5rem]">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-1">{{ __("Revenus") }}</p>
                                <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($totalRevenue, 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ $currency }}</small></p>
                            </div>
                            <div class="bg-slate-900 text-white p-5 rounded-[1.5rem]">
                                <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-1">{{ __("Marge nette") }}</p>
                                <p class="text-2xl font-black leading-none {{ $totalMargin < 0 ? 'text-rose-400' : '' }}">{{ number_format($totalMargin, 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ $currency }}</small></p>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="bg-white p-12 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <i class="fa-solid fa-calendar-days text-4xl text-slate-200 mb-4"></i>
                    <p class="text-[11px] font-black uppercase text-slate-400 tracking-widest">{{ __("Aucune campagne pour cette année") }}</p>
                </div>
            @endforelse

        </div>
    </div>
</x-app-layout>
