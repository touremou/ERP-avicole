<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-700 rounded-[1.5rem] flex items-center justify-center text-white shadow-xl">
                    <i class="fa-solid fa-water text-lg"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">
                        Rapport Pisciculture
                    </h2>
                    <p class="text-[10px] font-black text-blue-600 uppercase tracking-[0.2em] mt-1 italic">
                        Qualité de l'eau & survie par bassin
                    </p>
                </div>
            </div>
            {{-- Status filter --}}
            <div class="flex items-center gap-2">
                @foreach(['Actif' => 'Actifs', 'all' => 'Tous', 'Terminé' => 'Terminés'] as $val => $label)
                <a href="{{ route('reports.aquaculture', ['status' => $val]) }}"
                   @class(['px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all no-underline',
                       'bg-blue-700 text-white shadow' => $statusFilter === $val,
                       'bg-slate-100 text-slate-500 hover:bg-slate-200' => $statusFilter !== $val])>
                    {{ $label }}
                </a>
                @endforeach
                <a href="{{ route('reports.aquaculture.pdf', ['status' => $statusFilter]) }}"
                   class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all no-underline bg-slate-800 text-white hover:bg-slate-700 flex items-center gap-2">
                    <i class="fa-solid fa-file-pdf"></i> PDF
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- KPI global alertes --}}
            <div class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-blue-950 text-white p-6 rounded-[2rem] flex items-center gap-6 shadow-xl col-span-1 md:col-span-1">
                    <div class="w-14 h-14 bg-blue-700 rounded-[1.2rem] flex items-center justify-center text-2xl">🐟</div>
                    <div>
                        <p class="text-[8px] font-black uppercase tracking-[0.3em] opacity-60">Bassins suivis</p>
                        <p class="text-4xl font-black italic tracking-tighter">{{ $batchStats->count() }}</p>
                    </div>
                </div>
                <div @class([
                    'p-6 rounded-[2rem] flex items-center gap-6 shadow-xl text-white',
                    'bg-rose-600' => $criticalCount > 0,
                    'bg-amber-500' => $criticalCount === 0 && $totalAlerts > 0,
                    'bg-emerald-700' => $totalAlerts === 0,
                ])>
                    <div class="w-14 h-14 bg-white/20 rounded-[1.2rem] flex items-center justify-center text-2xl">
                        @if($criticalCount > 0) ⛔ @elseif($totalAlerts > 0) ⚠️ @else ✅ @endif
                    </div>
                    <div>
                        <p class="text-[8px] font-black uppercase tracking-[0.3em] opacity-70">Alertes qualité d'eau</p>
                        <p class="text-4xl font-black italic tracking-tighter">{{ $totalAlerts }}</p>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] flex items-center gap-6 shadow-sm border border-slate-100">
                    <div class="w-14 h-14 bg-rose-100 rounded-[1.2rem] flex items-center justify-center text-2xl text-rose-600 font-black">{{ $criticalCount }}</div>
                    <div>
                        <p class="text-[8px] font-black uppercase tracking-[0.3em] text-slate-400">Alertes critiques</p>
                        <p class="text-[10px] font-black text-slate-500 uppercase">pH, O₂, NH₃ hors limites strictes</p>
                    </div>
                </div>
            </div>

            {{-- Batch cards --}}
            <div class="space-y-4">
                @forelse($batchStats as $stat)
                @php $batch = $stat['batch']; $ext = $stat['last_ext']; $alerts = $stat['alerts']; @endphp
                <div class="bg-white rounded-[2rem] border border-slate-100 p-6 shadow-sm">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-blue-100 rounded-2xl flex items-center justify-center text-xl">
                                {{ $batch->species?->icon ?? '🐟' }}
                            </div>
                            <div>
                                <a href="{{ route('batches.show', $batch->id) }}"
                                   class="text-sm font-black text-slate-800 uppercase no-underline hover:text-blue-700">
                                    {{ $batch->code }}
                                </a>
                                <p class="text-[9px] text-slate-400 font-black uppercase mt-0.5">
                                    {{ $batch->species?->name_fr ?? 'Aquaculture' }} · {{ $batch->building?->name ?? '—' }} · J{{ $stat['age_days'] }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 flex-wrap">
                            @if($ext)
                                <div class="text-center">
                                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Temp.</p>
                                    <p class="text-lg font-black text-slate-700">{{ $ext->water_temp !== null ? number_format($ext->water_temp, 1).'°C' : '—' }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">pH</p>
                                    <p class="text-lg font-black text-slate-700">{{ $ext->water_ph !== null ? number_format($ext->water_ph, 2) : '—' }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">O₂</p>
                                    <p class="text-lg font-black text-slate-700">{{ $ext->water_o2_ppm !== null ? number_format($ext->water_o2_ppm, 1).' ppm' : '—' }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">NH₃</p>
                                    <p class="text-lg font-black text-slate-700">{{ $ext->water_ammonia_ppm !== null ? number_format($ext->water_ammonia_ppm, 2).' ppm' : '—' }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Biomasse</p>
                                    <p class="text-lg font-black text-slate-700">{{ $ext->biomass_kg !== null ? number_format($ext->biomass_kg, 1).' kg' : '—' }}</p>
                                </div>
                                <div class="text-center min-w-[80px]">
                                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Survie</p>
                                    @php $survival = $ext->survival_rate; @endphp
                                    @if($survival !== null)
                                    <p @class(['text-2xl font-black italic',
                                        'text-emerald-600' => $survival >= 80,
                                        'text-amber-600'   => $survival >= 60 && $survival < 80,
                                        'text-rose-600'    => $survival < 60])>
                                        {{ number_format($survival, 1) }}<small class="text-[10px] opacity-60">%</small>
                                    </p>
                                    @else
                                    <p class="text-slate-300 text-sm font-black uppercase">—</p>
                                    @endif
                                </div>
                            @else
                                <p class="text-slate-300 text-[10px] font-black uppercase tracking-widest">Aucune donnée d'eau enregistrée</p>
                            @endif

                            <div>
                                <span @class(['px-3 py-1 rounded-xl text-[8px] font-black uppercase tracking-widest',
                                    'bg-emerald-100 text-emerald-700' => $batch->status === 'Actif',
                                    'bg-slate-100 text-slate-500' => $batch->status !== 'Actif'])>
                                    {{ $batch->status }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Alertes en cours --}}
                    @if(!empty($alerts))
                    <div class="mt-4 pt-4 border-t border-slate-50 flex flex-wrap gap-2">
                        @foreach($alerts as $alert)
                        <span @class(['px-3 py-1.5 rounded-xl text-[9px] font-black uppercase tracking-widest',
                            'bg-rose-100 text-rose-700' => $alert['level'] === 'critical',
                            'bg-amber-100 text-amber-700' => $alert['level'] === 'warning'])>
                            {{ $alert['level'] === 'critical' ? '⛔' : '⚠️' }} {{ $alert['message'] }}
                        </span>
                        @endforeach
                    </div>
                    @endif

                    {{-- Mini sparkline pH --}}
                    @if(count($stat['series']['ph']) >= 2)
                    <div class="mt-4 pt-4 border-t border-slate-50">
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-2">Évolution pH</p>
                        <div class="flex gap-2 overflow-x-auto">
                            @foreach($stat['series']['ph'] as $date => $value)
                            <div class="flex flex-col items-center min-w-[50px]">
                                <div class="text-[10px] font-black text-slate-700">{{ number_format($value, 2) }}</div>
                                <div @class(['w-1 rounded-full mt-1', $value < 6.5 || $value > 8.5 ? 'bg-rose-400' : 'bg-blue-400'])
                                     style="height: {{ max(8, min(60, $value * 6)) }}px"></div>
                                <div class="text-[7px] text-slate-400 mt-1 uppercase font-black">{{ $date }}</div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- Mini sparkline survie --}}
                    @if(count($stat['series']['survival']) >= 2)
                    <div class="mt-4 pt-4 border-t border-slate-50">
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-2">Courbe de survie</p>
                        <div class="flex gap-2 overflow-x-auto">
                            @foreach($stat['series']['survival'] as $date => $value)
                            <div class="flex flex-col items-center min-w-[50px]">
                                <div class="text-[10px] font-black text-slate-700">{{ number_format($value, 0) }}%</div>
                                <div @class(['w-1 rounded-full mt-1',
                                    'bg-emerald-400' => $value >= 80,
                                    'bg-amber-400'   => $value >= 60 && $value < 80,
                                    'bg-rose-400'    => $value < 60])
                                     style="height: {{ max(8, min(60, $value * 0.6)) }}px"></div>
                                <div class="text-[7px] text-slate-400 mt-1 uppercase font-black">{{ $date }}</div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
                @empty
                <div class="bg-white rounded-[2rem] border border-dashed border-slate-200 p-12 text-center">
                    <p class="text-4xl mb-4">🐟</p>
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest">Aucun lot pisciculture {{ $statusFilter === 'Actif' ? 'actif' : '' }} trouvé</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
