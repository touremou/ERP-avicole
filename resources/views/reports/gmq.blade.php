<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-emerald-700 rounded-[1.5rem] flex items-center justify-center text-white shadow-xl">
                    <i class="fa-solid fa-chart-line text-lg"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">
                        Rapport GMQ — Engraissement
                    </h2>
                    <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-1 italic">
                        Gain Moyen Quotidien par lot — Ruminants, Porcins, Lapins
                    </p>
                </div>
            </div>
            {{-- Status filter --}}
            <div class="flex items-center gap-2">
                @foreach(['Actif' => 'Actifs', 'all' => 'Tous', 'Terminé' => 'Terminés'] as $val => $label)
                <a href="{{ route('reports.gmq', ['status' => $val]) }}"
                   @class(['px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all no-underline',
                       'bg-emerald-700 text-white shadow' => $statusFilter === $val,
                       'bg-slate-100 text-slate-500 hover:bg-slate-200' => $statusFilter !== $val])>
                    {{ $label }}
                </a>
                @endforeach
                <a href="{{ route('reports.gmq.pdf', ['status' => $statusFilter]) }}"
                   class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all no-underline bg-slate-800 text-white hover:bg-slate-700 flex items-center gap-2">
                    <i class="fa-solid fa-file-pdf"></i> PDF
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- KPI global --}}
            @if($avgGmq)
            <div class="mb-8 bg-emerald-800 text-white p-6 rounded-[2rem] flex items-center gap-6 shadow-xl">
                <div class="w-14 h-14 bg-emerald-600 rounded-[1.2rem] flex items-center justify-center text-2xl">📈</div>
                <div>
                    <p class="text-[8px] font-black uppercase tracking-[0.3em] opacity-60">GMQ Moyen — Ensemble des lots</p>
                    <p class="text-4xl font-black italic tracking-tighter">{{ number_format($avgGmq, 0) }} <small class="text-lg opacity-60">g/jour</small></p>
                </div>
            </div>
            @endif

            {{-- Batch cards --}}
            <div class="space-y-4">
                @forelse($batchStats as $stat)
                @php $batch = $stat['batch']; $gmq = $stat['gmq']; @endphp
                <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-emerald-100 rounded-2xl flex items-center justify-center text-xl">
                                {{ $batch->species?->icon ?? '📈' }}
                            </div>
                            <div>
                                <a href="{{ route('batches.show', $batch->id) }}"
                                   class="text-sm font-black text-slate-800 uppercase no-underline hover:text-emerald-700">
                                    {{ $batch->code }}
                                </a>
                                <p class="text-[9px] text-slate-400 font-black uppercase mt-0.5">
                                    {{ $batch->species?->name_fr ?? 'Ruminant' }} · {{ $batch->building?->name ?? '—' }} · J{{ $stat['age_days'] }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-8">
                            {{-- Poids départ --}}
                            <div class="text-center">
                                <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Poids départ</p>
                                <p class="text-lg font-black text-slate-700">
                                    {{ $stat['start_weight'] ? number_format($stat['start_weight'], 3).' kg' : '—' }}
                                </p>
                            </div>
                            {{-- Poids actuel --}}
                            <div class="text-center">
                                <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Dernier poids</p>
                                <p class="text-lg font-black text-slate-700">
                                    {{ $stat['last_weight'] ? number_format($stat['last_weight'], 3).' kg' : '—' }}
                                </p>
                            </div>
                            {{-- GMQ --}}
                            <div class="text-center min-w-[100px]">
                                <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">GMQ</p>
                                @if($gmq !== null)
                                <p @class(['text-2xl font-black italic',
                                    'text-emerald-600' => $gmq >= 150,
                                    'text-amber-600'   => $gmq >= 80 && $gmq < 150,
                                    'text-rose-600'    => $gmq < 80])>
                                    {{ number_format($gmq, 0) }} <small class="text-[10px] opacity-60">g/j</small>
                                </p>
                                @else
                                <p class="text-slate-300 text-sm font-black uppercase">Données insuffisantes</p>
                                @endif
                            </div>
                            {{-- Statut --}}
                            <div>
                                <span @class(['px-3 py-1 rounded-xl text-[8px] font-black uppercase tracking-widest',
                                    'bg-emerald-100 text-emerald-700' => $batch->status === 'Actif',
                                    'bg-slate-100 text-slate-500' => $batch->status !== 'Actif'])>
                                    {{ $batch->status }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Portées (porcins, lapins) --}}
                    @if(($stat['total_born'] ?? 0) > 0)
                    <div class="mt-4 pt-4 border-t border-slate-50 flex flex-wrap gap-8">
                        <div class="text-center">
                            <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Naissances</p>
                            <p class="text-lg font-black text-emerald-700">{{ number_format($stat['total_born']) }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Sevrages</p>
                            <p class="text-lg font-black text-teal-700">{{ number_format($stat['total_weaned']) }}</p>
                        </div>
                        @if($stat['avg_litter_size'])
                        <div class="text-center">
                            <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Portée moy.</p>
                            <p class="text-lg font-black text-indigo-700">{{ number_format($stat['avg_litter_size'], 1) }}</p>
                        </div>
                        @endif
                        @if($stat['weaning_rate'] !== null)
                        <div class="text-center">
                            <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Taux sevrage</p>
                            <p @class(['text-lg font-black',
                                'text-emerald-700' => $stat['weaning_rate'] >= 90,
                                'text-amber-700'   => $stat['weaning_rate'] < 90 && $stat['weaning_rate'] >= 75,
                                'text-rose-700'    => $stat['weaning_rate'] < 75])>{{ number_format($stat['weaning_rate'], 1) }}%</p>
                        </div>
                        @endif
                    </div>
                    @endif

                    {{-- Mini sparkline des pesées --}}
                    @if(count($stat['gmq_series']) >= 2)
                    <div class="mt-4 pt-4 border-t border-slate-50 flex gap-2 overflow-x-auto">
                        @foreach($stat['gmq_series'] as $date => $weight)
                        <div class="flex flex-col items-center min-w-[50px]">
                            <div class="text-[10px] font-black text-slate-700">{{ number_format($weight, 2) }}</div>
                            <div class="w-1 bg-emerald-400 rounded-full mt-1" style="height: {{ max(8, min(60, $weight * 15)) }}px"></div>
                            <div class="text-[7px] text-slate-400 mt-1 uppercase font-black">{{ $date }}</div>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @empty
                <div class="bg-white rounded-[2rem] border border-dashed border-slate-200 p-12 text-center">
                    <p class="text-4xl mb-4">📈</p>
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Aucun lot en engraissement {{ $statusFilter === 'Actif' ? 'actif' : '' }} trouvé</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
