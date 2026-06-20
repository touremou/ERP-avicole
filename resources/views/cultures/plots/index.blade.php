<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-map text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Parcelles") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Production Végétale — Assolement") }}</p>
                </div>
            </div>
            @can('cultures.C')
            <button onclick="document.getElementById('plot-create').classList.toggle('hidden')"
                class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> {{ __("Nouvelle Parcelle") }}
            </button>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            @can('cultures.C')
            {{-- FORMULAIRE CRÉATION --}}
            <div id="plot-create" class="hidden bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-green-500 tracking-widest italic mb-6">{{ __("Nouvelle parcelle") }}</h3>
                <form action="{{ route('plots.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom *") }}</label>
                        <input type="text" name="name" required class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <input type="text" name="code" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic uppercase">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Superficie (ha) *") }}</label>
                        <input type="number" step="0.01" min="0" name="area_ha" required class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type de sol") }}</label>
                        <input type="text" name="soil_type" placeholder="{{ __('Argileux, sableux…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Irrigation") }}</label>
                        <input type="text" name="irrigation_type" placeholder="{{ __('Pluvial, goutte-à-goutte…') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Localisation") }}</label>
                        <input type="text" name="location" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div class="md:col-span-3 flex justify-end">
                        <button type="submit" class="bg-slate-900 text-white px-10 py-4 rounded-[2rem] font-black uppercase italic tracking-widest text-[10px] hover:bg-green-600 transition">
                            <i class="fa-solid fa-check mr-2"></i> {{ __("Enregistrer") }}
                        </button>
                    </div>
                </form>
            </div>
            @endcan

            {{-- LISTE --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($plots as $plot)
                    @php
                        $badge = match($plot->status) {
                            \App\Models\Plot::STATUS_EN_CULTURE => 'bg-green-50 text-green-600',
                            \App\Models\Plot::STATUS_JACHERE    => 'bg-amber-50 text-amber-600',
                            \App\Models\Plot::STATUS_INACTIVE   => 'bg-slate-100 text-slate-400',
                            default                             => 'bg-blue-50 text-blue-600',
                        };
                    @endphp
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <div class="flex justify-between items-start mb-3">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $plot->status)) }}</span>
                            @if($plot->code)<span class="text-[8px] font-black text-slate-300 uppercase">{{ $plot->code }}</span>@endif
                        </div>
                        <h3 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ $plot->name }}</h3>
                        <p class="text-[9px] text-slate-400 uppercase mt-2">{{ number_format($plot->area_ha, 2, ',', ' ') }} ha
                            @if($plot->soil_type) · {{ $plot->soil_type }} @endif
                        </p>
                        <div class="mt-4 pt-4 border-t border-slate-50 flex justify-between text-[9px] font-black uppercase text-slate-400">
                            <span>{{ $plot->crop_cycles_count }} {{ __("cycles") }}</span>
                            @if($plot->active_cycle_count > 0)<span class="text-green-600">{{ __("Occupée") }}</span>@endif
                        </div>
                    </div>
                @empty
                    <div class="md:col-span-3 text-center text-slate-300 text-[10px] font-black uppercase italic py-16 bg-white rounded-[3rem] border border-slate-100">
                        {{ __("Aucune parcelle enregistrée") }}
                    </div>
                @endforelse
            </div>

            <div>{{ $plots->links() }}</div>
        </div>
    </div>
</x-app-layout>
