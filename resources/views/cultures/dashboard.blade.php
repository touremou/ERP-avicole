<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Production Végétale')" :subtitle="__('Pilotage des parcelles & cultures')" icon="fa-seedling" accent="green">
            <x-slot name="actions">
                @can('cultures.C')
                <a href="{{ route('crop-cycles.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouveau Cycle") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>

        {{-- Sous-navigation hub (pilotage + référentiel) — partagée avec les pages Catalogue/Protocoles/Recettes --}}
        @include('cultures.partials.hub-tabs')
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            {{-- ACCÈS GROUPÉS (hub-cartes) : entités opérationnelles du module, pour
                 que le breadcrumb puisse rester « Tableau de bord » seul. --}}
            @can('cultures.L')
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm not-italic">
                <p class="text-[10px] font-black uppercase tracking-widest text-green-600 mb-4">{{ __("Opérations") }}</p>
                <div class="grid grid-cols-3 md:grid-cols-5 gap-3">
                    @foreach([
                        ['label' => 'Parcelles', 'icon' => 'fa-map-location-dot', 'route' => 'plots.index'],
                        ['label' => 'Cycles', 'icon' => 'fa-arrows-spin', 'route' => 'crop-cycles.index'],
                        ['label' => 'Campagnes', 'icon' => 'fa-flag', 'route' => 'crop-campaigns.index'],
                        ['label' => 'Transformation', 'icon' => 'fa-blender', 'route' => 'crop-transformations.index'],
                        ['label' => 'Rapports', 'icon' => 'fa-chart-line', 'route' => 'crop-reports.index'],
                    ] as $it)
                        @if(\Illuminate\Support\Facades\Route::has($it['route']))
                        <a href="{{ route($it['route']) }}" class="flex flex-col items-center justify-center gap-2 p-4 bg-slate-50 rounded-2xl hover:bg-green-50 hover:text-green-600 transition-all no-underline text-slate-600 text-center">
                            <i class="fa-solid {{ $it['icon'] }} text-lg"></i>
                            <span class="text-[8px] font-black uppercase tracking-widest leading-tight">{{ __($it['label']) }}</span>
                        </a>
                        @endif
                    @endforeach
                </div>
            </div>
            @endcan

            <x-flash />

            {{-- ================================================================ --}}
            {{-- TAB 1 — VUE D'ENSEMBLE                                          --}}
            {{-- ================================================================ --}}
            @if($activeTab === 'overview')

                {{-- INDICATEURS --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-stat-tile :label="__('Parcelles')" :value="$stats['plots_total']" :sub="$stats['plots_occupied'] . ' ' . __('en culture')" accent="green" />
                    <x-stat-tile :label="__('Cycles actifs')" :value="$stats['cycles_active']" accent="green" />
                    <x-stat-tile :label="__('Surface cultivée')" :value="number_format($stats['area_cultivated'], 2, ',', ' ')" unit="ha" accent="green" />
                    <x-stat-tile :label="__('Récolté (30 j)')" :value="number_format($stats['harvest_30d'], 0, ',', ' ')" unit="kg" :sub="number_format($stats['harvest_ytd'], 0, ',', ' ') . ' kg ' . __('cette année')" accent="green" :dark="true" />
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

                {{-- RÉCOLTES À VENIR --}}
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

                {{-- ALERTES AGRONOMIQUES --}}
                @if(!empty($agronomicAlerts))
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-green-500 tracking-widest italic mb-4"><i class="fa-solid fa-leaf mr-1"></i> {{ __("Alertes agronomiques") }}</h3>
                    <div class="space-y-3">
                        @foreach($agronomicAlerts as $alert)
                            @php
                                $advColor = match($alert['severity']) {
                                    'critique'  => 'bg-rose-50 border-rose-200 text-rose-700',
                                    'attention' => 'bg-amber-50 border-amber-200 text-amber-700',
                                    'conseil'   => 'bg-green-50 border-green-200 text-green-700',
                                    default     => 'bg-slate-50 border-slate-200 text-slate-500',
                                };
                            @endphp
                            <a href="{{ route('crop-cycles.show', $alert['cycle']) }}" class="no-underline flex items-start gap-4 p-4 rounded-[2rem] border {{ $advColor }} hover:opacity-90 transition">
                                <i class="fa-solid {{ $alert['icon'] }} text-lg mt-0.5"></i>
                                <div>
                                    <p class="text-[11px] font-black uppercase tracking-tight italic leading-none">{{ $alert['title'] }} <span class="opacity-60">· {{ $alert['cycle']->crop_name }}@if($alert['cycle']->plot) ({{ $alert['cycle']->plot->name }})@endif</span></p>
                                    <p class="text-[11px] font-bold italic mt-2 opacity-90">{{ $alert['message'] }}</p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- SUGGESTIONS DE CULTURE (PARCELLES LIBRES) --}}
                @php $hasSuggestions = collect($plotSuggestions ?? [])->contains(fn ($s) => !empty($s['top'])); @endphp
                @if($hasSuggestions)
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-green-500 tracking-widest italic mb-4"><i class="fa-solid fa-seedling mr-1"></i> {{ __("Suggestions de culture (parcelles libres)") }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($plotSuggestions as $sug)
                            @continue(empty($sug['top']))
                            @php $sp = $sug['top']['species']; @endphp
                            <a href="{{ route('plots.show', $sug['plot']) }}" class="no-underline flex items-center justify-between p-4 rounded-[1.5rem] bg-slate-50 hover:bg-green-50 transition">
                                <div>
                                    <p class="text-[10px] font-black uppercase text-slate-400 italic leading-none">{{ $sug['plot']->name }}</p>
                                    <p class="text-[12px] font-black uppercase text-slate-800 italic mt-1"><i class="fa-solid {{ $sp->type_icon }} text-green-500 mr-1"></i>{{ $sp->name }}</p>
                                </div>
                                <span class="text-[8px] font-black uppercase italic px-2 py-0.5 rounded-full {{ $sug['top']['in_season'] ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400' }}">{{ $sug['top']['in_season'] ? __('en saison') : __('hors saison') }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- CYCLES EN COURS + RÉCOLTES RÉCENTES --}}
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
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

                {{-- RÉPARTITION DES SURFACES --}}
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

            {{-- ================================================================ --}}
            {{-- TAB 2 — CALENDRIER CULTURAL                                     --}}
            {{-- ================================================================ --}}
            @elseif($activeTab === 'calendar')

                {{-- FILTRE ANNÉE + LÉGENDE --}}
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <form method="GET" action="{{ route('cultures.dashboard') }}" class="flex items-center gap-3">
                        <input type="hidden" name="tab" value="calendar">
                        <span class="text-[9px] font-black text-slate-400 uppercase italic">{{ __("Année") }}</span>
                        <select name="year" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-2 font-black text-slate-800 shadow-sm italic text-[11px] cursor-pointer">
                            @foreach($calendarYears as $y)<option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>@endforeach
                        </select>
                    </form>
                    <div class="flex items-center gap-4 text-[8px] font-black uppercase text-slate-400">
                        <span><span class="inline-block w-3 h-3 bg-green-200 rounded-sm align-middle"></span> {{ __("En culture") }}</span>
                        <span><span class="inline-block w-3 h-3 bg-green-600 rounded-sm align-middle"></span> {{ __("Semis") }}</span>
                        <span><span class="inline-block w-3 h-3 bg-amber-500 rounded-sm align-middle"></span> {{ __("Récolte") }}</span>
                    </div>
                </div>

                <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                    <th class="p-3 sticky left-0 bg-white">{{ __("Culture") }}</th>
                                    @foreach(['J','F','M','A','M','J','J','A','S','O','N','D'] as $mLabel)
                                        <th class="p-2 text-center w-8">{{ $mLabel }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($calendarRows as $row)
                                    <tr class="border-b border-slate-50">
                                        <td class="p-3 sticky left-0 bg-white">
                                            <a href="{{ route('crop-cycles.show', $row['cycle']) }}" class="no-underline">
                                                <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $row['cycle']->crop_name }}</p>
                                                <p class="text-[8px] text-slate-400 uppercase mt-0.5">{{ $row['cycle']->plot?->name }}</p>
                                            </a>
                                        </td>
                                        @for($m = 1; $m <= 12; $m++)
                                            @php $cell = $row['months'][$m]; @endphp
                                            <td class="p-1 text-center">
                                                @if($cell['planting'])
                                                    <div class="h-6 bg-green-600 rounded-sm flex items-center justify-center" title="Semis"><i class="fa-solid fa-seedling text-white text-[8px]"></i></div>
                                                @elseif($cell['harvest'])
                                                    <div class="h-6 bg-amber-500 rounded-sm flex items-center justify-center" title="{{ __('Récolte') }}"><i class="fa-solid fa-wheat-awn text-white text-[8px]"></i></div>
                                                @elseif($cell['occupied'])
                                                    <div class="h-6 bg-green-200 rounded-sm"></div>
                                                @else
                                                    <div class="h-6"></div>
                                                @endif
                                            </td>
                                        @endfor
                                    </tr>
                                @empty
                                    <tr><td colspan="13" class="p-16 text-center text-slate-300 text-[10px] font-black uppercase italic">{{ __("Aucun cycle sur") }} {{ $year }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @can('cultures.C')
                <div class="flex justify-end gap-3">
                    <a href="{{ route('crop-calendar-events.create') }}" class="bg-white text-slate-700 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-slate-100 transition-all shadow-sm border border-slate-100 italic flex items-center gap-2 no-underline">
                        <i class="fa-solid fa-calendar-plus text-green-500"></i> {{ __("Ajouter un événement") }}
                    </a>
                    <a href="{{ route('crop-cycles.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                        <i class="fa-solid fa-plus"></i> {{ __("Nouveau Cycle") }}
                    </a>
                </div>
                @endcan

                {{-- ÉVÉNEMENTS CALENDAIRES LIBRES --}}
                @if($calendarEvents->isNotEmpty())
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic"><i class="fa-solid fa-calendar-days mr-1 text-green-500"></i> {{ __("Événements") }} {{ $year }}</h3>
                        <a href="{{ route('crop-calendar-events.index') }}" class="text-[9px] font-black uppercase text-slate-400 hover:text-green-600 transition no-underline italic">{{ __("Voir tout") }} →</a>
                    </div>
                    <div class="space-y-2">
                        @foreach($calendarEvents->take(10) as $event)
                            @php
                                $colorMap = [
                                    'green'  => 'bg-green-100 text-green-700',
                                    'blue'   => 'bg-blue-100 text-blue-700',
                                    'amber'  => 'bg-amber-100 text-amber-700',
                                    'red'    => 'bg-red-100 text-red-700',
                                    'purple' => 'bg-purple-100 text-purple-700',
                                    'slate'  => 'bg-slate-100 text-slate-700',
                                ];
                                $badgeClass = $colorMap[$event->color] ?? 'bg-green-100 text-green-700';
                            @endphp
                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-[1.25rem]">
                                <div class="flex items-center gap-3">
                                    <span class="text-[9px] font-black text-slate-500 uppercase w-16 shrink-0">{{ $event->event_date->format('d/m') }}</span>
                                    <span class="px-2 py-0.5 rounded-lg text-[8px] font-black uppercase {{ $badgeClass }}">{{ $event->type_label }}</span>
                                    <span class="text-[10px] font-black text-slate-800 italic">{{ $event->title }}</span>
                                </div>
                                @can('cultures.M')
                                <a href="{{ route('crop-calendar-events.edit', $event) }}" class="text-[9px] font-black uppercase text-slate-300 hover:text-green-600 transition no-underline italic ml-4">
                                    <i class="fa-solid fa-pen"></i>
                                </a>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
                @else
                <div class="bg-white p-6 rounded-[3rem] border border-slate-100 shadow-sm flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase text-slate-300 italic"><i class="fa-solid fa-calendar-days mr-2"></i> {{ __("Aucun événement calendaire cette année") }}</p>
                    @can('cultures.C')
                    <a href="{{ route('crop-calendar-events.create') }}" class="text-[9px] font-black uppercase text-green-600 hover:text-green-800 transition no-underline italic">
                        <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                    </a>
                    @endcan
                </div>
                @endif

            {{-- ================================================================ --}}
            {{-- TAB — MÉTÉO                                                     --}}
            {{-- ================================================================ --}}
            @elseif($activeTab === 'meteo')

                {{-- FILTRES + bouton nouveau relevé --}}
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <form method="GET" action="{{ route('cultures.dashboard') }}" class="flex flex-wrap items-end gap-3 bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm">
                        <input type="hidden" name="tab" value="meteo">
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Mois") }}</label>
                            <input type="month" name="month" value="{{ $weatherMonth }}" class="bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle") }}</label>
                            <select name="plot_id" class="bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] cursor-pointer">
                                <option value="">{{ __("Toutes") }}</option>
                                @foreach($plots as $p)<option value="{{ $p->id }}" @selected($weatherPlotId == $p->id)>{{ $p->name }}</option>@endforeach
                            </select>
                        </div>
                        <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black uppercase italic tracking-widest text-[9px] shadow-lg hover:bg-sky-600 transition-all">{{ __("Afficher") }}</button>
                    </form>
                    @can('cultures.C')
                    <button onclick="document.getElementById('weather-modal').classList.remove('hidden')" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-sky-600 transition-all shadow-2xl italic flex items-center gap-2">
                        <i class="fa-solid fa-plus"></i> {{ __("Nouveau relevé") }}
                    </button>
                    @endcan
                </div>

                {{-- KPI MÉTÉO --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-sky-500 text-white p-6 rounded-[2rem] shadow-lg">
                        <p class="text-[8px] font-black text-sky-100 uppercase tracking-widest italic mb-2">{{ __("Pluie totale") }}</p>
                        <p class="text-3xl font-black leading-none">{{ number_format($weatherStats['rainfall_total'], 0, ',', ' ') }} <small class="text-[10px] opacity-60">mm</small></p>
                    </div>
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-rose-500 uppercase tracking-widest italic mb-2">{{ __("T° max moy.") }}</p>
                        <p class="text-3xl font-black text-slate-900 leading-none">{{ $weatherStats['t_max_avg'] }}<small class="text-[10px] opacity-40">°C</small></p>
                    </div>
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-sky-500 uppercase tracking-widest italic mb-2">{{ __("Pluie moy./relevé") }}</p>
                        <p class="text-3xl font-black text-slate-900 leading-none">{{ $weatherStats['rainfall_avg'] }} <small class="text-[10px] opacity-40">mm</small></p>
                    </div>
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Relevés") }}</p>
                        <p class="text-3xl font-black text-slate-900 leading-none">{{ $weatherStats['count'] }}</p>
                    </div>
                </div>

                {{-- TABLEAU MÉTÉO --}}
                <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                    <th class="p-4">{{ __("Date") }}</th>
                                    <th class="p-4">{{ __("Parcelle") }}</th>
                                    <th class="p-4 text-right">{{ __("T° min") }}</th>
                                    <th class="p-4 text-right">{{ __("T° max") }}</th>
                                    <th class="p-4 text-right">{{ __("Pluie") }}</th>
                                    <th class="p-4 text-right">{{ __("Humidité") }}</th>
                                    <th class="p-4 text-right">{{ __("Vent") }}</th>
                                    <th class="p-4 text-right">{{ __("Soleil") }}</th>
                                    @canany(['cultures.M', 'cultures.S'])<th class="p-4"></th>@endcanany
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($weatherReadings as $r)
                                    <tr class="border-b border-slate-50 text-[11px] text-slate-700">
                                        <td class="p-4 font-black">{{ $r->reading_date?->format('d/m/Y') }}</td>
                                        <td class="p-4 text-slate-400">{{ $r->plot?->name ?? '—' }}</td>
                                        <td class="p-4 text-right text-sky-600">{{ $r->temperature_min !== null ? number_format($r->temperature_min, 1, ',', ' ').'°' : '—' }}</td>
                                        <td class="p-4 text-right text-rose-600">{{ $r->temperature_max !== null ? number_format($r->temperature_max, 1, ',', ' ').'°' : '—' }}</td>
                                        <td class="p-4 text-right font-black text-sky-700">{{ $r->rainfall_mm > 0 ? number_format($r->rainfall_mm, 1, ',', ' ').' mm' : '—' }}</td>
                                        <td class="p-4 text-right">{{ $r->humidity_pct !== null ? round($r->humidity_pct).'%' : '—' }}</td>
                                        <td class="p-4 text-right">{{ $r->wind_kmh !== null ? round($r->wind_kmh).' km/h' : '—' }}</td>
                                        <td class="p-4 text-right">{{ $r->sunshine_h !== null ? number_format($r->sunshine_h, 1, ',', ' ').'h' : '—' }}</td>
                                        @canany(['cultures.M', 'cultures.S'])
                                        <td class="p-4 text-right">
                                            <div class="flex items-center justify-end gap-3">
                                                @can('cultures.M')
                                                <a href="{{ route('weather.edit', $r) }}" class="text-slate-300 hover:text-sky-600 no-underline"><i class="fa-solid fa-pen-to-square text-xs"></i></a>
                                                @endcan
                                                @can('cultures.S')
                                                <form action="{{ route('weather.destroy', $r) }}" method="POST" onsubmit="return confirm('Supprimer ce relevé ?')">
                                                    @csrf @method('DELETE')
                                                    <button class="text-rose-300 hover:text-rose-600"><i class="fa-solid fa-trash text-xs"></i></button>
                                                </form>
                                                @endcan
                                            </div>
                                        </td>
                                        @endcanany
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="p-16 text-center text-slate-300 text-[10px] font-black uppercase italic">{{ __("Aucun relevé pour ce mois") }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            @endif

        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- MODAL NOUVEAU RELEVÉ MÉTÉO (rendu sur la page meteo uniquement)  --}}
    {{-- ================================================================ --}}
    @if($activeTab === 'meteo')
    @can('cultures.C')
    <div id="weather-modal" class="hidden fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] p-8 max-w-2xl w-full shadow-2xl italic">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-[12px] font-black uppercase text-slate-800 tracking-widest italic"><i class="fa-solid fa-cloud-sun-rain text-sky-500 mr-2"></i>{{ __("Nouveau relevé météo") }}</h3>
                <button onclick="document.getElementById('weather-modal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <form action="{{ route('weather.store') }}" method="POST" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @csrf
                <div class="col-span-2 md:col-span-1">
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date *") }}</label>
                    <input type="date" name="reading_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-2">
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Parcelle") }}</label>
                    <select name="plot_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] cursor-pointer">
                        <option value="">{{ __("-- Ferme entière --") }}</option>
                        @foreach($plots as $p)<option value="{{ $p->id }}" @selected($weatherPlotId == $p->id)>{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("T° min (°C)") }}</label>
                    <input type="number" step="0.1" name="temperature_min" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("T° max (°C)") }}</label>
                    <input type="number" step="0.1" name="temperature_max" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Humidité (%)") }}</label>
                    <input type="number" step="1" min="0" max="100" name="humidity_pct" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Pluie (mm)") }}</label>
                    <input type="number" step="0.1" min="0" name="rainfall_mm" value="0" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Vent (km/h)") }}</label>
                    <input type="number" step="0.1" min="0" name="wind_kmh" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Soleil (h)") }}</label>
                    <input type="number" step="0.5" min="0" max="24" name="sunshine_h" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <input type="text" name="notes" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-bold text-slate-700 shadow-inner italic text-[11px]">
                </div>
                <div class="col-span-2 md:col-span-3 flex justify-end pt-2">
                    <button type="submit" class="bg-slate-900 text-white px-10 py-4 rounded-[2rem] font-black uppercase italic tracking-widest text-[10px] shadow-xl hover:bg-sky-600 transition-all"><i class="fa-solid fa-check mr-2 text-sky-400"></i>{{ __("Enregistrer") }}</button>
                </div>
            </form>
        </div>
    </div>
    @endcan
    @endif

</x-app-layout>
