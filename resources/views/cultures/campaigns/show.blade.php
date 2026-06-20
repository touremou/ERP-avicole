<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-{{ $campaign->season_color }}-500 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-calendar-week text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $campaign->name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ $campaign->season_label }} · {{ $campaign->year }}</p>
                </div>
            </div>
            <a href="{{ route('crop-campaigns.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Campagnes") }}
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">
            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Cycles") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $campaign->cycles->count() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Surface") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($campaign->cycles->sum('area_used_ha'), 2, ',', ' ') }} <small class="text-[10px] opacity-40">ha</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Récolté") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($campaign->total_harvested, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Objectif") }}</p>
                    <p class="text-3xl font-black leading-none">{{ $campaign->target_production_t ? number_format($campaign->target_production_t, 1, ',', ' ').' t' : '—' }}</p>
                    @if($campaign->progress_percent !== null)<p class="text-[9px] text-green-400 uppercase mt-1">{{ $campaign->progress_percent }}% {{ __("atteint") }}</p>@endif
                </div>
            </div>

            {{-- CYCLES --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-700 tracking-widest italic mb-6">{{ __("Cycles de la campagne") }}</h3>
                @forelse($campaign->cycles as $cycle)
                    <a href="{{ route('crop-cycles.show', $cycle) }}" class="flex items-center justify-between p-4 mb-2 bg-slate-50 rounded-[1.5rem] hover:bg-green-50 transition no-underline">
                        <div>
                            <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $cycle->crop_name }} @if($cycle->variety)<span class="text-slate-400">· {{ $cycle->variety }}</span>@endif</p>
                            <p class="text-[9px] text-slate-400 uppercase mt-1">{{ $cycle->plot?->name }} · {{ number_format($cycle->area_used_ha, 2, ',', ' ') }} ha · {{ $cycle->planting_date?->format('d/m/Y') }}</p>
                        </div>
                        <p class="text-base font-black text-green-600 leading-none">{{ number_format($cycle->total_harvested, 0, ',', ' ') }} <small class="text-[9px] opacity-40">kg</small></p>
                    </a>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-10">{{ __("Aucun cycle rattaché à cette campagne") }}</p>
                @endforelse
            </div>

            {{-- ÉDITION STATUT --}}
            @can('cultures.M')
            <form action="{{ route('crop-campaigns.update', $campaign) }}" method="POST" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-4">
                @csrf @method('PUT')
                <input type="hidden" name="name" value="{{ $campaign->name }}">
                <input type="hidden" name="season" value="{{ $campaign->season }}">
                <input type="hidden" name="start_date" value="{{ $campaign->start_date?->toDateString() }}">
                <input type="hidden" name="end_date_planned" value="{{ $campaign->end_date_planned?->toDateString() }}">
                <input type="hidden" name="target_production_t" value="{{ $campaign->target_production_t }}">
                <input type="hidden" name="notes" value="{{ $campaign->notes }}">
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Statut") }}</label>
                    <select name="status" class="bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] cursor-pointer">
                        @foreach($statuses as $key => $label)
                            <option value="{{ $key }}" @selected($campaign->status == $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black uppercase italic tracking-widest text-[9px] shadow-lg hover:bg-green-600 transition-all">{{ __("Mettre à jour") }}</button>
            </form>
            @endcan
        </div>
    </div>
</x-app-layout>
