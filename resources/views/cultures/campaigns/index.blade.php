<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Campagnes agricoles')" :subtitle="__('Pilotage des saisons culturales')" icon="fa-calendar-week" accent="green">
            <x-slot name="actions">
                @can('cultures.C')
                <a href="{{ route('crop-campaigns.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle campagne") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">
            <x-flash />

            {{-- FILTRE ANNÉE --}}
            <form method="GET" class="flex items-center gap-3">
                <span class="text-[9px] font-black text-slate-400 uppercase italic">{{ __("Année") }}</span>
                <select name="year" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-2 font-black text-slate-800 shadow-sm italic text-[11px] cursor-pointer">
                    @foreach($years as $y)
                        <option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>
                    @endforeach
                </select>
                <span class="ml-auto text-[9px] font-black text-green-600 uppercase italic">{{ $campaigns->count() }} {{ __("campagne(s)") }}</span>
            </form>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($campaigns as $c)
                    @php $season = \App\Models\CropCampaign::SEASONS[$c->season] ?? ['color' => 'slate']; @endphp
                    <a href="{{ route('crop-campaigns.show', $c) }}" class="block bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm hover:border-green-200 transition no-underline">
                        <div class="flex items-start justify-between mb-3">
                            <span class="text-[8px] font-black uppercase bg-{{ $season['color'] }}-100 text-{{ $season['color'] }}-700 px-3 py-1 rounded-full">{{ $c->season_label }}</span>
                            @php $st = $c->status === 'en_cours' ? 'green' : ($c->status === 'cloturee' ? 'slate' : 'amber'); @endphp
                            <span class="text-[8px] font-black uppercase text-{{ $st }}-500">{{ $c->status_label }}</span>
                        </div>
                        <p class="text-[13px] font-black uppercase text-slate-800 italic leading-tight">{{ $c->name }}</p>
                        @if($c->code)<p class="text-[9px] text-slate-400 uppercase mt-0.5">{{ $c->code }}</p>@endif
                        <p class="text-[9px] text-slate-400 uppercase mt-2">
                            {{ $c->start_date?->format('d/m/Y') }} → {{ $c->end_date_planned?->format('d/m/Y') ?? '?' }}
                        </p>
                        <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-50">
                            <span class="text-[9px] text-slate-500 uppercase"><i class="fa-solid fa-layer-group"></i> {{ $c->cycles_count }} {{ __("cycles") }}</span>
                            <span class="text-[11px] font-black text-green-600">{{ number_format($c->total_harvested, 0, ',', ' ') }} <small class="text-[8px] opacity-50">kg</small></span>
                        </div>
                        @if($c->progress_percent !== null)
                            <div class="mt-3">
                                <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-green-500 rounded-full" style="width: {{ $c->progress_percent }}%"></div>
                                </div>
                                <p class="text-[8px] text-slate-400 uppercase mt-1">{{ $c->progress_percent }}% {{ __("de l'objectif") }} ({{ number_format($c->target_production_t, 1, ',', ' ') }} t)</p>
                            </div>
                        @endif
                    </a>
                @empty
                    <div class="md:col-span-3 bg-white p-16 rounded-[3rem] border border-slate-100 text-center">
                        <i class="fa-solid fa-calendar-week text-5xl text-slate-200 mb-4"></i>
                        <p class="text-slate-400 text-[11px] font-black uppercase italic">{{ __("Aucune campagne en") }} {{ $year }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
