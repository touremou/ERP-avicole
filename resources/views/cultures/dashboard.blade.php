<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-seedling text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Production Végétale") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Pilotage des parcelles & cultures") }}</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('crop-transformations.index') }}" class="bg-white text-slate-700 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm border border-slate-100 italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-industry text-green-500"></i> {{ __("Transformation") }}
                </a>
                @can('cultures.C')
                <a href="{{ route('crop-cycles.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouveau Cycle") }}
                </a>
                @endcan
            </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-4">
            @foreach([
                ['cultures.calendar', 'fa-calendar-days', 'Calendrier'],
                ['crop-campaigns.index', 'fa-calendar-week', 'Campagnes'],
                ['crop-catalogue.index', 'fa-book-open', 'Catalogue'],
                ['crop-recipes.index', 'fa-book', 'Recettes'],
                ['weather.index', 'fa-cloud-sun-rain', 'Météo'],
                ['plots.index', 'fa-map', 'Parcelles'],
            ] as $link)
                <a href="{{ route($link[0]) }}" class="bg-white text-slate-600 px-4 py-2 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-green-50 hover:text-green-700 transition-all shadow-sm border border-slate-100 italic flex items-center gap-2 no-underline">
                    <i class="fa-solid {{ $link[1] }} text-green-500"></i> {{ __($link[2]) }}
                </a>
            @endforeach
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- INDICATEURS --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Parcelles") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['plots_total'] }}</p>
                    <p class="text-[9px] text-slate-400 uppercase mt-1">{{ $stats['plots_occupied'] }} {{ __("en culture") }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Cycles actifs") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['cycles_active'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Surface cultivée") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($stats['area_cultivated'], 2, ',', ' ') }} <small class="text-[10px] opacity-40">ha</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Récolté (30 j)") }}</p>
                    <p class="text-3xl font-black leading-none">{{ number_format($stats['harvest_30d'], 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                    <p class="text-[9px] text-green-400/70 uppercase mt-1">{{ number_format($stats['harvest_ytd'], 0, ',', ' ') }} kg {{ __("cette année") }}</p>
                </div>
            </div>

            {{-- CAMPAGNE EN COURS + INDICATEURS SECONDAIRES --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                @if($activeCampaign)
                <a href="{{ route('crop-campaigns.show', $activeCampaign) }}" class="lg:col-span-2 bg-gradient-to-br from-green-600 to-emerald-700 text-white p-6 rounded-[2.5rem] shadow-lg no-underline flex items-center justify-between">
                    <div>
                        <p class="text-[8px] font-black text-green-200 uppercase tracking-widest italic mb-1"><i class="fa-solid fa-calendar-week mr-1"></i> {{ __("Campagne en cours") }}</p>
                        <p class="text-lg font-black uppercase italic leading-none">{{ $activeCampaign->name }}</p>
                        <p class="text-[9px] text-green-200 uppercase mt-1">{{ $activeCampaign->season_label }} · {{ $activeCampaign->cycles_count ?? $activeCampaign->cycles->count() }} {{ __("cycles") }}</p>
                    </div>
                    @if($activeCampaign->progress_percent !== null)
                    <div class="text-right">
                        <p class="text-3xl font-black leading-none">{{ $activeCampaign->progress_percent }}%</p>
                        <p class="text-[8px] text-green-200 uppercase mt-1">{{ __("de l'objectif") }}</p>
                    </div>
                    @endif
                </a>
                @else
                <div class="lg:col-span-2 bg-white p-6 rounded-[2.5rem] border border-dashed border-slate-200 flex items-center justify-center">
                    <a href="{{ route('crop-campaigns.create') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-green-600 italic no-underline"><i class="fa-solid fa-plus mr-2"></i>{{ __("Démarrer une campagne") }}</a>
                </div>
                @endif
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-sky-500 uppercase tracking-widest italic mb-1">{{ __("Pluie 30 j") }}</p>
                        <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($stats['rainfall_30d'], 0, ',', ' ') }} <small class="text-[9px] opacity-40">mm</small></p>
                    </div>
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-1">{{ __("Transfo. 30 j") }}</p>
                        <p class="text-2xl font-black text-slate-900 leading-none">{{ $stats['transform_30d'] }}</p>
                    </div>
                </div>
            </div>

            {{-- CALENDRIER CULTURAL : RÉCOLTES À VENIR --}}
            @if($dueCycles->isNotEmpty())
            <div class="bg-amber-50 border border-amber-200 p-6 rounded-[2.5rem]">
                <h3 class="text-[10px] font-black uppercase text-amber-600 tracking-widest italic mb-4"><i class="fa-solid fa-calendar-day mr-1"></i> {{ __("Récoltes à venir (14 j)") }}</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($dueCycles as $c)
                        @php $diff = (int) now()->startOfDay()->diffInDays($c->expected_harvest_date->copy()->startOfDay(), false); @endphp
                        <a href="{{ route('crop-cycles.show', $c) }}" class="bg-white px-4 py-2 rounded-2xl border border-amber-100 no-underline hover:border-amber-300 transition">
                            <span class="text-[10px] font-black uppercase text-slate-800 italic">{{ $c->crop_name }}</span>
                            <span class="text-[8px] font-black uppercase ml-2 {{ $diff < 0 ? 'text-rose-600' : 'text-amber-600' }}">
                                {{ $diff < 0 ? '⚠️ retard '.abs($diff).'j' : ($diff === 0 ? "aujourd'hui" : "dans {$diff}j") }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- CYCLES EN COURS --}}
                <div class="lg:col-span-2 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Cycles en cours") }}</h3>
                    @forelse($activeCycles as $cycle)
                        <a href="{{ route('crop-cycles.show', $cycle) }}" class="flex items-center justify-between p-4 mb-2 bg-slate-50 rounded-[1.5rem] hover:bg-green-50 transition no-underline">
                            <div>
                                <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $cycle->crop_name }} @if($cycle->variety)<span class="text-slate-400">· {{ $cycle->variety }}</span>@endif</p>
                                <p class="text-[9px] text-slate-400 uppercase mt-1">{{ $cycle->plot?->name }} · {{ $cycle->age }} {{ __("j") }} · {{ number_format($cycle->area_used_ha, 2, ',', ' ') }} ha</p>
                            </div>
                            <div class="text-right">
                                <p class="text-base font-black text-green-600 leading-none">{{ number_format($cycle->total_harvested, 0, ',', ' ') }} <small class="text-[9px] opacity-40">kg</small></p>
                                <p class="text-[8px] text-slate-400 uppercase mt-1">{{ __("récolté") }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-10">{{ __("Aucun cycle en cours") }}</p>
                    @endforelse
                </div>

                {{-- RÉCOLTES RÉCENTES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Récoltes récentes") }}</h3>
                    @forelse($recentHarvests as $h)
                        <div class="flex items-center justify-between py-3 border-b border-slate-50">
                            <div>
                                <p class="text-[10px] font-black uppercase text-slate-700 italic leading-none">{{ $h->cropCycle?->crop_name ?? '—' }}</p>
                                <p class="text-[8px] text-slate-400 uppercase mt-1">{{ $h->harvest_date?->format('d/m/Y') }}</p>
                            </div>
                            <p class="text-sm font-black text-slate-900">{{ number_format($h->quantity, 0, ',', ' ') }} <small class="text-[8px] opacity-40">{{ $h->unit }}</small></p>
                        </div>
                    @empty
                        <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-10">{{ __("Aucune récolte") }}</p>
                    @endforelse
                </div>
            </div>

            {{-- RÉPARTITION DES SURFACES (cycles en cours) --}}
            @if($cropMix->isNotEmpty())
            @php $maxArea = max($cropMix->max('area'), 0.0001); @endphp
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Répartition des surfaces emblavées") }}</h3>
                <div class="space-y-3">
                    @foreach($cropMix as $mix)
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[10px] font-black uppercase text-slate-700 italic">{{ $mix->crop_name }} <span class="text-slate-300">· {{ $mix->cycles }} cycle(s)</span></span>
                                <span class="text-[10px] font-black text-green-600">{{ number_format($mix->area, 2, ',', ' ') }} ha</span>
                            </div>
                            <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-green-500 rounded-full" style="width: {{ round($mix->area / $maxArea * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
