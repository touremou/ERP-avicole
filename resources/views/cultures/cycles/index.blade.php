<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-leaf text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Cycles de Culture") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Du semis à la récolte") }}</p>
                </div>
            </div>
            @can('cultures.C')
            <a href="{{ route('crop-cycles.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-plus"></i> {{ __("Nouveau Cycle") }}
            </a>
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

            {{-- FILTRES --}}
            <div class="flex gap-2">
                <a href="{{ route('crop-cycles.index') }}" class="{{ $filter !== 'archives' ? 'bg-slate-900 text-white' : 'bg-white text-slate-400' }} px-6 py-2 rounded-full text-[9px] font-black uppercase tracking-widest no-underline">{{ __("Actifs") }}</a>
                <a href="{{ route('crop-cycles.index', ['filter' => 'archives']) }}" class="{{ $filter === 'archives' ? 'bg-slate-900 text-white' : 'bg-white text-slate-400' }} px-6 py-2 rounded-full text-[9px] font-black uppercase tracking-widest no-underline">{{ __("Archives") }}</a>
            </div>

            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-50">
                        <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="p-5">{{ __("Culture") }}</th>
                            <th class="p-5">{{ __("Parcelle") }}</th>
                            <th class="p-5 text-center">{{ __("Semis") }}</th>
                            <th class="p-5 text-center">{{ __("Surface") }}</th>
                            <th class="p-5 text-right">{{ __("Récolté") }}</th>
                            <th class="p-5 text-center">{{ __("Statut") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($cycles as $cycle)
                            @php
                                $badge = match($cycle->status) {
                                    \App\Models\CropCycle::STATUS_EN_COURS  => 'bg-green-50 text-green-600',
                                    \App\Models\CropCycle::STATUS_RECOLTE   => 'bg-amber-50 text-amber-600',
                                    \App\Models\CropCycle::STATUS_TERMINE   => 'bg-slate-100 text-slate-500',
                                    default                                 => 'bg-rose-50 text-rose-600',
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/50 transition cursor-pointer" onclick="window.location='{{ route('crop-cycles.show', $cycle) }}'">
                                <td class="p-5">
                                    <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $cycle->crop_name }}</p>
                                    @if($cycle->variety)<p class="text-[8px] text-slate-400 uppercase mt-1">{{ $cycle->variety }}</p>@endif
                                </td>
                                <td class="p-5 text-[10px] font-black text-slate-500 uppercase">{{ $cycle->plot?->name ?? '—' }}</td>
                                <td class="p-5 text-center text-[10px] font-bold text-slate-500">{{ $cycle->planting_date?->format('d/m/Y') }}</td>
                                <td class="p-5 text-center text-[10px] font-black text-slate-700">{{ number_format($cycle->area_used_ha, 2, ',', ' ') }} ha</td>
                                <td class="p-5 text-right text-[11px] font-black text-green-600">{{ number_format($cycle->total_harvested, 0, ',', ' ') }} kg</td>
                                <td class="p-5 text-center"><span class="px-3 py-1 rounded-full text-[8px] font-black uppercase {{ $badge }}">{{ ucfirst($cycle->status) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-16 text-center text-slate-300 text-[10px] font-black uppercase italic">{{ __("Aucun cycle de culture") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $cycles->links() }}</div>
        </div>
    </div>
</x-app-layout>
