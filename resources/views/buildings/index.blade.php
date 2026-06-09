<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex flex-col">
                <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">Parc de Production</h2>
                <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.3em] mt-1 italic">Surveillance en temps réel des unités</span>
            </div>
            
            @can('elevage.C')
            <a href="{{ route('buildings.create') }}" class="bg-slate-900 text-white px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/10 no-underline">
                <i class="fas fa-plus mr-2"></i> Nouveau Bâtiment
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12 italic text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            {{-- SYSTÈME DE FILTRES --}}
            <div class="flex flex-wrap gap-2 mb-10 bg-white p-2 rounded-3xl border border-slate-100 shadow-sm w-fit mx-auto md:mx-0">
                <button onclick="filterB('all')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase bg-slate-900 text-white transition-all cursor-pointer border-none" id="f-all">Tous</button>
                <button onclick="filterB('mixte')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-blue-600 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-mixte">🔄 Mixte</button>
                <button onclick="filterB('poussiniere')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-poussiniere">🐣 Poussinières</button>
                <button onclick="filterB('chair')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-chair">🍗 Chair</button>
                <button onclick="filterB('ponte')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-ponte">🥚 Ponte</button>
                <button onclick="filterB('reproducteur')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-reproducteur">🧬 Repro</button>
                <button onclick="filterB('bergerie')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-bergerie">🐑 Bergerie</button>
                <button onclick="filterB('chevrerie')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-chevrerie">🐐 Chèvrerie</button>
                <button onclick="filterB('bassin')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-bassin">🐟 Bassin</button>
                <button onclick="filterB('lapiniere')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-lapiniere">🐇 Lapinière</button>
                <button onclick="filterB('porcherie')" class="btn-f px-6 py-3 rounded-2xl text-[9px] font-black uppercase text-slate-400 hover:bg-slate-50 transition-all cursor-pointer border-none bg-transparent" id="f-porcherie">🐷 Porcherie</button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="buildingContainer">
                @foreach($buildings as $building)
                    @php
                        // Grâce au Eager Loading de l'index : Ceci ne déclenche aucune requête SQL N+1 !
                        $activeBatches = $building->batches; 
                        $occupation = $activeBatches->sum('current_quantity');
                        $reste = max(0, $building->capacity - $occupation);
                        $percent = ($building->capacity > 0) ? ($occupation / $building->capacity) * 100 : 0;
                        
                        $totalInitial = $activeBatches->sum('initial_quantity');
                        $totalMorts = $activeBatches->sum(function($batch) {
                            return ($batch->qty_dead ?? 0) + $batch->dailyChecks->sum('mortality');
                        });
                        
                        $tauxMortalite = ($totalInitial > 0) ? ($totalMorts / $totalInitial) * 100 : 0;

                        $mortalityClass = "text-emerald-500 bg-emerald-50 border-emerald-100";
                        if($tauxMortalite >= 2) $mortalityClass = "text-amber-500 bg-amber-50 border-amber-100";
                        if($tauxMortalite >= 5) $mortalityClass = "text-red-500 bg-red-50 border-red-100";

                        $statusClass = match($building->status) {
                            'Occupé' => 'text-orange-500 bg-orange-50 border-orange-100',
                            'En désinfection' => 'text-purple-600 bg-purple-50 border-purple-200 animate-pulse',
                            default => 'text-blue-500 bg-blue-50 border-blue-100',
                        };
                    @endphp

                    <div class="b-card bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden transition-all duration-500 hover:shadow-2xl group" data-type="{{ $building->type }}">
                        <div class="p-8">
                            <div class="flex justify-between items-start mb-6">
                                <span class="text-[8px] font-black uppercase px-3 py-1 bg-slate-50 rounded-lg border border-slate-100 italic text-slate-500">
                                    {{ $building->type }}
                                </span>
                                
                                <div class="flex flex-col items-end gap-2">
                                    @if($occupation > 0)
                                        <span class="text-[8px] font-black uppercase px-3 py-1 rounded-lg border italic {{ $mortalityClass }}">
                                            <i class="fas fa-heartbeat mr-1"></i> Mort. {{ number_format($tauxMortalite, 1) }}%
                                        </span>
                                    @endif
                                    
                                    <span class="text-[8px] font-black uppercase px-3 py-1 rounded-lg border italic {{ $statusClass }}">
                                        @if($building->status === 'En désinfection')
                                            <i class="fas fa-biohazard mr-1"></i>
                                        @endif
                                        {{ $building->status }}
                                    </span>
                                </div>
                            </div>

                            <h3 class="text-2xl font-black text-slate-800 uppercase leading-none mb-4 tracking-tighter">{{ $building->name }}</h3>
                            
                            {{-- BARRE DE CAPACITÉ --}}
                            <div class="space-y-2 mb-6">
                                <div class="flex justify-between text-[9px] font-black uppercase italic tracking-widest">
                                    <span class="text-slate-400">Occupation</span>
                                    <span class="{{ $percent > 95 ? 'text-red-600' : 'text-slate-800' }}">{{ number_format($occupation) }} / {{ number_format($building->capacity) }}</span>
                                </div>
                                <div class="w-full h-3 bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                    <div class="h-full transition-all duration-1000 {{ $percent > 90 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ min($percent, 100) }}%"></div>
                                </div>
                                <div class="flex justify-between text-[8px] font-bold text-slate-400 uppercase tracking-tighter">
                                    <span>{{ number_format($percent, 1) }}% utilisé</span>
                                    @if($building->status === 'En désinfection')
                                        <span class="text-purple-600">En quarantaine</span>
                                    @else
                                        <span class="text-blue-600">{{ number_format($reste) }} places libres</span>
                                    @endif
                                </div>
                            </div>

                            {{-- STATS TECHNIQUES --}}
                            <div class="grid grid-cols-2 gap-4 pt-4 border-t border-slate-50">
                                <div class="flex flex-col">
                                    <p class="text-[8px] font-black text-slate-400 uppercase italic">Lots Actifs</p>
                                    <p class="text-xs font-black text-slate-700">{{ $activeBatches->count() }} unité(s)</p>
                                </div>
                                <div class="flex flex-col items-end">
                                    <p class="text-[8px] font-black text-slate-400 uppercase italic text-right">Age Moyen</p>
                                    <p class="text-xs font-black text-slate-700">
                                        {{ $activeBatches->count() > 0 ? round($activeBatches->avg('age')) : 0 }} jours
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="px-8 pb-8 flex gap-3">
                            @can('elevage.L')
                            <a href="{{ route('buildings.show', $building->id) }}" class="flex-1 text-center py-4 bg-slate-50 hover:bg-slate-900 hover:text-white rounded-2xl text-[9px] font-black uppercase transition-all tracking-widest border border-slate-100 shadow-inner group-hover:scale-[1.02] no-underline text-slate-700">
                                <i class="fas fa-eye mr-2"></i> Details
                            </a>
                            @endcan

                            @can('elevage.M')
                            <a href="{{ route('buildings.edit', $building->id) }}" class="flex-1 text-center py-4 bg-slate-50 hover:bg-blue-600 hover:text-white rounded-2xl text-[9px] font-black uppercase transition-all tracking-widest border border-slate-100 shadow-inner group-hover:scale-[1.02] no-underline text-slate-700">
                                <i class="fas fa-edit mr-2"></i> Modifier
                            </a>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <script>
        function filterB(type) {
            document.querySelectorAll('.b-card').forEach(card => {
                card.style.display = (type === 'all' || card.dataset.type === type) ? 'block' : 'none';
            });
            document.querySelectorAll('.btn-f').forEach(btn => {
                btn.classList.remove('bg-slate-900', 'text-white');
                btn.classList.add('text-slate-400', 'bg-transparent');
            });
            const active = document.getElementById('f-' + type);
            if(active) {
                active.classList.remove('text-slate-400', 'bg-transparent');
                active.classList.add('bg-slate-900', 'text-white');
            }
        }
    </script>
</x-app-layout>