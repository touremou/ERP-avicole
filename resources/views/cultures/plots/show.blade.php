<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-map text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $plot->name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ number_format($plot->area_ha, 2, ',', ' ') }} ha @if($plot->soil_type) · {{ $plot->soil_type }} @endif</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('plots.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Retour") }}
                </a>
                @can('cultures.M')
                <a href="{{ route('plots.edit', $plot) }}" class="bg-white border border-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-pen"></i> {{ __("Modifier") }}
                </a>
                @endcan
                @can('cultures.S')
                @if(!$plot->isOccupied())
                <form action="{{ route('plots.destroy', $plot) }}" method="POST" onsubmit="return confirm('{{ __('Supprimer cette parcelle ?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-rose-500 hover:text-rose-700 px-3 py-2.5 font-black text-[9px] uppercase tracking-widest italic transition">
                        <i class="fa-solid fa-trash mr-1"></i> {{ __("Supprimer") }}
                    </button>
                </form>
                @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="p-5 bg-rose-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg"></i> {{ session('error') }}
                </div>
            @endif

            @php
                $badge = match($plot->status) {
                    \App\Models\Plot::STATUS_EN_CULTURE => 'bg-green-50 text-green-600',
                    \App\Models\Plot::STATUS_JACHERE    => 'bg-amber-50 text-amber-600',
                    \App\Models\Plot::STATUS_INACTIVE   => 'bg-slate-100 text-slate-400',
                    default                             => 'bg-blue-50 text-blue-600',
                };
                $activeCycles = $plot->cropCycles->whereIn('status', ['en_cours', 'recolte'])->count();
            @endphp

            {{-- KPI --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase ml-1 mb-1 italic">{{ __("Superficie") }}</p>
                    <p class="text-2xl font-black text-slate-800 italic">{{ number_format($plot->area_ha, 2, ',', ' ') }} <span class="text-sm text-slate-400">ha</span></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase ml-1 mb-1 italic">{{ __("Statut") }}</p>
                    <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $plot->status)) }}</span>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase ml-1 mb-1 italic">{{ __("Cycles total") }}</p>
                    <p class="text-2xl font-black text-slate-800 italic">{{ $plot->cropCycles->count() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase ml-1 mb-1 italic">{{ __("Cycles actifs") }}</p>
                    <p class="text-2xl font-black text-green-600 italic">{{ $activeCycles }}</p>
                </div>
            </div>

            {{-- CYCLES --}}
            <div class="bg-white p-6 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-green-500 tracking-widest italic mb-6 ml-2">{{ __("Cycles de culture") }}</h3>
                <div class="space-y-3">
                    @forelse($plot->cropCycles as $cycle)
                        @php
                            $cBadge = match($cycle->status) {
                                'en_cours' => 'bg-green-50 text-green-600',
                                'recolte'  => 'bg-amber-50 text-amber-600',
                                'termine'  => 'bg-slate-100 text-slate-400',
                                default    => 'bg-blue-50 text-blue-600',
                            };
                        @endphp
                        <a href="{{ route('crop-cycles.show', $cycle) }}" class="no-underline flex items-center justify-between bg-slate-50 hover:bg-green-50 rounded-2xl p-4 transition">
                            <div>
                                <p class="text-sm font-black text-slate-800 uppercase italic tracking-tight leading-none">{{ $cycle->crop_name }}@if($cycle->variety) <span class="text-slate-400">· {{ $cycle->variety }}</span>@endif</p>
                                <p class="text-[9px] text-slate-400 uppercase mt-1">{{ $cycle->planting_date ? \Carbon\Carbon::parse($cycle->planting_date)->format('d/m/Y') : '—' }}</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-[9px] font-black text-slate-600 uppercase">{{ number_format($cycle->total_harvested, 2, ',', ' ') }} kg</span>
                                <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase {{ $cBadge }}">{{ ucfirst(str_replace('_', ' ', $cycle->status)) }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="text-center text-slate-300 text-[10px] font-black uppercase italic py-12">
                            {{ __("Aucun cycle sur cette parcelle") }}
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- DETAILS --}}
            <div class="bg-white p-6 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-green-500 tracking-widest italic mb-6 ml-2">{{ __("Détails") }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <p class="text-sm font-black text-slate-800 italic ml-2">{{ $plot->code ?: '—' }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type de sol") }}</label>
                        <p class="text-sm font-black text-slate-800 italic ml-2">{{ $plot->soil_type ?: '—' }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Irrigation") }}</label>
                        <p class="text-sm font-black text-slate-800 italic ml-2">{{ $plot->irrigation_type ?: '—' }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Localisation") }}</label>
                        <p class="text-sm font-black text-slate-800 italic ml-2">{{ $plot->location ?: '—' }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                        <p class="text-sm font-bold text-slate-700 italic ml-2">{{ $plot->notes ?: '—' }}</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
