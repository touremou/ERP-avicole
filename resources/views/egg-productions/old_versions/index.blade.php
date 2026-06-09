<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 md:w-14 md:h-14 bg-emerald-600 rounded-2xl md:rounded-3xl flex items-center justify-center text-white shadow-xl shadow-emerald-500/20 rotate-3 transition-transform hover:rotate-0 flex-shrink-0">
                    <i class="fa-solid fa-layer-group text-xl md:text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl md:text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none m-0">🥚 Dashboard Production</h2>
                    <p class="text-[9px] md:text-[10px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic leading-none m-0">Gestion des Flux & Inventaire</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 md:gap-3 w-full md:w-auto">
                @can('L')
                <a href="{{ route('stocks.index', ['category' => 'oeufs']) }}" class="flex-1 md:flex-none text-center bg-white border border-slate-200 text-slate-700 px-4 py-2.5 md:px-6 md:py-3 rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-slate-50 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-box-open mr-1 md:mr-2 text-emerald-500"></i> Magasin
                </a>
                <a href="{{ route('reports.index') }}" class="flex-1 md:flex-none text-center bg-white border border-slate-200 text-slate-700 px-4 py-2.5 md:px-6 md:py-3 rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-slate-50 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-chart-pie mr-1 md:mr-2 text-blue-500"></i> Rapports
                </a>
                @endcan

                @can('M')
                <a href="{{ route('stocks.maintenance') }}" 
                    class="w-full md:w-auto flex items-center justify-center gap-2 px-4 py-2.5 md:px-6 md:py-3 bg-slate-900 text-white rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-red-600 transition-all shadow-xl shadow-slate-200 no-underline">
                        <i class="fa-solid fa-wrench text-red-400"></i> <span class="md:inline">Inventaire</span>
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-6 md:py-10 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8 md:space-y-10">

            {{-- SECTION 1 : KPI DE STOCK (SCROLLABLE SUR MOBILE) --}}
            {{-- Le conteneur parent déborde sur mobile pour permettre le scroll horizontal --}}
            <div class="-mx-4 px-4 sm:mx-0 sm:px-0">
                <div class="flex md:grid md:grid-cols-5 gap-4 md:gap-6 overflow-x-auto pb-4 snap-x snap-mandatory md:overflow-visible md:pb-0 [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]">
                    
                    {{-- RECOLTE DU JOUR --}}
                    <div class="min-w-[280px] md:min-w-0 snap-center bg-slate-900 p-6 md:p-8 rounded-[2.5rem] md:rounded-[3.5rem] text-white shadow-xl relative overflow-hidden group text-left border-none">
                        <p class="text-[9px] md:text-[10px] font-black text-slate-500 uppercase mb-2 tracking-widest italic leading-none m-0">Flux Entrant (Brut)</p>
                        <h3 class="text-4xl md:text-5xl font-black text-white leading-none tracking-tighter m-0 mt-2">{{ number_format($totalEggsToday) }}</h3>
                        <div class="mt-4 flex items-center gap-2 text-[7px] md:text-[8px] uppercase font-black text-emerald-400/80 bg-emerald-500/10 w-fit px-3 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Collecte Jour
                        </div>
                        <i class="fa-solid fa-arrow-right-to-bracket absolute -right-4 -bottom-4 text-white/5 text-7xl md:text-8xl"></i>
                    </div>

                    {{-- RESERVE BRUTE --}}
                    <div class="min-w-[280px] md:min-w-0 snap-center bg-white p-6 md:p-8 rounded-[2.5rem] md:rounded-[3.5rem] border border-orange-100 shadow-lg shadow-orange-500/5 relative group text-left">
                        <div class="absolute top-4 right-4 md:top-6 md:right-6 px-2 py-1 md:px-3 md:py-1 bg-orange-100 text-orange-600 text-[7px] md:text-[8px] font-black rounded-lg italic uppercase tracking-tighter">Attente Tri</div>
                        <p class="text-[9px] md:text-[10px] font-black text-orange-400 uppercase mb-2 tracking-widest italic leading-none m-0">Réserve Brute</p>
                        <h3 class="text-3xl md:text-4xl font-black text-slate-800 tracking-tighter m-0 mt-2">{{ number_format($stockNonTrie) }}</h3>
                        <p class="text-[7px] md:text-[8px] text-slate-400 mt-2 uppercase italic leading-none m-0">Unités non calibrées</p>
                    </div>

                    {{-- STOCK MAGASIN CALIBRÉ --}}
                    <div class="min-w-[280px] md:min-w-0 snap-center bg-emerald-500 p-6 md:p-8 rounded-[2.5rem] md:rounded-[3.5rem] text-white shadow-xl shadow-emerald-500/20 relative group text-left overflow-hidden border-none">
                        <p class="text-[9px] md:text-[10px] font-black text-emerald-100 uppercase mb-2 tracking-widest italic leading-none m-0">Stock Magasin</p>
                        <h3 class="text-4xl md:text-5xl font-black tracking-tighter m-0 mt-2">{{ number_format(array_sum($stockVendable), 2) }}</h3>
                        <p class="text-[8px] md:text-[9px] text-emerald-50 mt-3 md:mt-4 uppercase italic font-black tracking-tighter m-0">Alvéoles (S-XL)</p>
                        
                        <div class="mt-3 md:mt-4 flex flex-wrap gap-1 md:gap-1.5 opacity-100 md:opacity-80 md:group-hover:opacity-100 transition-opacity">
                            @foreach($stockVendable as $grade => $qty)
                                <span class="text-[6px] md:text-[7px] font-black uppercase bg-white/20 px-1.5 py-0.5 rounded border border-white/10 text-white">{{ strtoupper($grade) }}: {{ number_format($qty, 1) }}</span>
                            @endforeach
                        </div>
                        <i class="fa-solid fa-warehouse absolute -right-6 -bottom-6 text-white/10 text-8xl md:text-9xl"></i>
                    </div>
                    
                    {{-- PERTES --}}
                    <div class="min-w-[280px] md:min-w-0 snap-center bg-red-500 p-6 md:p-8 rounded-[2.5rem] md:rounded-[3.5rem] text-white shadow-xl shadow-red-500/20 relative group text-left overflow-hidden border-none">
                        <p class="text-[9px] md:text-[10px] font-black text-red-100 uppercase mb-2 tracking-widest italic leading-none m-0">Pertes en Stock</p>
                        <h3 class="text-3xl md:text-4xl font-black m-0 mt-2">
                            @php 
                                $itemCasse = $stockItems->where('item_name', 'Cassé')->first();
                                $itemAnomalie = $stockItems->where('item_name', 'Anomalie')->first();
                                $totalPertesAlv = ($itemCasse->current_quantity ?? 0) + ($itemAnomalie->current_quantity ?? 0);
                            @endphp
                            {{ number_format($totalPertesAlv, 2) }}
                        </h3>
                        <p class="text-[7px] md:text-[8px] text-red-50 mt-2 uppercase italic leading-none font-black tracking-tighter m-0">
                            ≈ {{ round($totalPertesAlv * 30) }} Œufs (Pertes)
                        </p>
                        <i class="fa-solid fa-dumpster absolute -right-6 -bottom-6 text-white/10 text-6xl md:text-7xl"></i>
                    </div>

                    {{-- REPARTITION GRAPHIQUE --}}
                    <div class="min-w-[280px] md:min-w-0 snap-center bg-white p-6 rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 flex flex-col justify-center gap-2 md:gap-3 shadow-sm relative overflow-hidden">
                        <div class="absolute -right-6 -top-6 w-16 h-16 bg-slate-50 rounded-full"></div>
                        @foreach(['XL' => 'blue', 'L' => 'indigo', 'M' => 'slate', 'S' => 'orange'] as $grade => $color)
                            @php $val = $stockVendable[strtolower($grade)] ?? 0; @endphp
                            <div class="flex items-center justify-between group relative z-10">
                                <span class="text-[9px] md:text-[10px] font-black uppercase text-{{$color}}-500 italic">{{ $grade }}</span>
                                <div class="flex-1 mx-2 h-[1px] bg-slate-100 border-b border-dashed border-slate-200"></div>
                                <span class="text-[10px] md:text-[11px] font-black text-slate-700 tracking-tighter">{{ number_format($val, 1) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- SECTION 2 : SUIVI TECHNIQUE PAR LOT (RESPONSIVE TABLE) --}}
            <div class="bg-white rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 overflow-hidden text-left">
                <div class="p-6 md:p-10 border-b border-slate-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/30">
                    <div class="flex items-center gap-3 md:gap-4">
                        <div class="w-1.5 md:w-2 h-8 md:h-10 bg-emerald-500 rounded-full"></div>
                        <div>
                            <h4 class="text-sm md:text-base font-black uppercase tracking-tight text-slate-800 italic leading-none m-0">📊 Performance Technique</h4>
                            <p class="text-[8px] md:text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-1 md:mt-2 italic leading-none m-0">Indice HDP & Collecte</p>
                        </div>
                    </div>
                    <div class="bg-slate-900 text-white px-4 py-1.5 md:px-5 md:py-2 rounded-lg md:rounded-xl text-[8px] md:text-[9px] font-black uppercase tracking-[0.2em] italic">
                        LIVE : {{ now()->format('H:i') }}
                    </div>
                </div>
                
                {{-- Conteneur pour scroll horizontal interne du tableau si écran vraiment trop petit --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[600px] md:min-w-0">
                        <thead>
                            <tr class="text-[8px] md:text-[9px] font-black text-slate-400 uppercase tracking-widest italic bg-slate-50/80">
                                <th class="px-6 md:px-10 py-4 md:py-5">Lot / Bâtiment</th>
                                <th class="px-4 py-4 md:py-5 text-center hidden sm:table-cell">Âge</th>
                                <th class="px-4 py-4 md:py-5 text-center">Récolte Jour</th>
                                <th class="px-4 py-4 md:py-5 text-center">Laying Rate (HDP)</th>
                                <th class="px-6 md:px-10 py-4 md:py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($activeBatches as $b)
                                @php
                                    $todayRecord = $b->eggProductions->where('production_date', '>=', now()->startOfDay())->first();
                                    $passagesCount = $todayRecord ? 1 + substr_count($todayRecord->observations ?? '', '[Nouveau passage]') : 0;
                                    $isGraded = $todayRecord ? $todayRecord->is_graded : false;
                                    $totalDayEggs = $todayRecord ? $todayRecord->total_eggs_collected : 0;
                                    $hdp = $b->current_quantity > 0 ? round(($totalDayEggs / $b->current_quantity) * 100, 1) : 0;
                                @endphp
                                <tr class="hover:bg-slate-50/80 transition-all font-bold group">
                                    <td class="px-6 md:px-10 py-5 md:py-8">
                                        <div class="flex items-center gap-3 md:gap-4">
                                            <div class="w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl bg-slate-100 flex items-center justify-center text-slate-400 text-xs md:text-sm shadow-inner group-hover:bg-emerald-500 group-hover:text-white transition-all flex-shrink-0">
                                                <i class="fa-solid fa-house-crack"></i>
                                            </div>
                                            <div>
                                                <p class="text-sm md:text-base font-black text-slate-800 uppercase tracking-tighter leading-none m-0">{{ $b->code }}</p>
                                                <p class="text-[8px] md:text-[9px] text-blue-500 uppercase mt-1 tracking-widest italic m-0">{{ $b->building->name }} <span class="sm:hidden text-slate-400">• S-{{ ceil($b->age / 7) }}</span></p>
                                            </div>
                                        </div>
                                        {{-- Jauge de passages miniaturisée sur mobile --}}
                                        <div class="flex items-center gap-1.5 md:gap-2 mt-3 md:mt-4">
                                            @for($i=0; $i<4; $i++)
                                                @if($i < $passagesCount)
                                                    @if($i == $passagesCount - 1 && auth()->user()->can('M')) 
                                                        <a href="{{ route('egg-productions.tri', $todayRecord->id) }}" 
                                                           @class(['w-5 h-2 md:w-7 md:h-2.5 rounded-full transition-all hover:scale-110 shadow-sm', 'bg-emerald-500 shadow-emerald-200' => $isGraded, 'bg-orange-400 animate-pulse shadow-orange-200' => !$isGraded]) 
                                                           title="{{ $isGraded ? 'Trié (Modifier)' : 'Trier le cumul' }}"></a>
                                                    @else
                                                        <div @class(['w-5 h-2 md:w-7 md:h-2.5 rounded-full shadow-sm', 'bg-emerald-500' => $isGraded, 'bg-orange-400' => !$isGraded])></div>
                                                    @endif
                                                @else
                                                    <div class="w-5 h-2 md:w-7 md:h-2.5 rounded-full bg-slate-100"></div>
                                                @endif
                                            @endfor
                                            <span class="text-[7px] md:text-[8px] text-slate-400 uppercase ml-1 md:ml-2 italic font-black hidden sm:inline">{{ $passagesCount }}/4 Passages</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-5 md:py-8 text-center text-[10px] md:text-xs text-slate-500 italic hidden sm:table-cell">S-{{ ceil($b->age / 7) }}</td>
                                    <td class="px-4 py-5 md:py-8 text-center text-xs md:text-sm text-slate-900 font-black italic">{{ number_format($totalDayEggs) }} <small class="text-[7px] md:text-[8px] opacity-40 uppercase hidden sm:inline">Unités</small></td>
                                    <td class="px-4 py-5 md:py-8 text-center">
                                        <div class="flex flex-col items-center gap-1.5 md:gap-2">
                                            <span @class(['px-3 md:px-5 py-1 md:py-1.5 rounded-full text-[9px] md:text-[10px] font-black italic shadow-sm', 
                                                'bg-emerald-50 text-emerald-600' => $hdp >= 80, 
                                                'bg-orange-50 text-orange-600' => $hdp < 80 && $hdp >= 50, 
                                                'bg-red-50 text-red-600' => $hdp < 50 && $hdp > 0, 
                                                'bg-slate-100 text-slate-400' => $hdp == 0])>{{ $hdp }}%</span>
                                            <div class="w-12 md:w-16 h-1 md:h-1.5 bg-slate-100 rounded-full overflow-hidden shadow-inner hidden sm:block">
                                                <div @class(['h-full transition-all duration-700', 'bg-emerald-500' => $hdp >= 80, 'bg-orange-400' => $hdp < 80 && $hdp >= 50, 'bg-red-500' => $hdp < 50]) style="width: {{ $hdp }}%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 md:px-10 py-5 md:py-8 text-right">
                                        @can('C')
                                            @if(!$isGraded && $passagesCount < 4)
                                                <a href="{{ route('egg-productions.create', ['batch_id' => $b->id]) }}" class="px-3 md:px-6 py-2 md:py-4 bg-slate-900 text-white rounded-xl md:rounded-2xl text-[8px] md:text-[10px] font-black uppercase shadow-lg shadow-slate-200 hover:bg-emerald-600 transition-all inline-flex items-center gap-1 md:gap-2 italic no-underline whitespace-nowrap">
                                                    <i class="fa-solid fa-plus text-emerald-400"></i> <span class="hidden sm:inline">Nouveau Passage</span><span class="sm:hidden">Collecter</span>
                                                </a>
                                            @elseif($isGraded)
                                                <span class="px-3 md:px-6 py-2 md:py-4 bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-xl md:rounded-2xl text-[8px] md:text-[10px] font-black uppercase shadow-sm inline-flex items-center gap-1 md:gap-2 italic cursor-default whitespace-nowrap">
                                                    <i class="fa-solid fa-check-double"></i> <span class="hidden sm:inline">Clôturé</span>
                                                </span>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- SECTION 3 : FLUX RECENTS (CÔTE A CÔTE SUR PC, EMPILÉS SUR MOBILE) --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-10 text-left">
                
                {{-- ENTRÉES --}}
                <div class="bg-white rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-xl shadow-slate-200/30 overflow-hidden relative">
                    <div class="p-6 md:p-8 border-b border-slate-50 bg-slate-50/20 flex flex-wrap gap-2 justify-between items-center italic">
                        <h4 class="text-xs md:text-sm font-black uppercase tracking-widest text-slate-800 leading-none italic m-0">📅 Entrées Brutes</h4>
                        <span class="px-2 py-1 bg-emerald-100 text-emerald-600 rounded-lg text-[7px] md:text-[8px] font-black uppercase italic">Flux Entrant</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[400px] md:min-w-0">
                            <tbody class="divide-y divide-slate-50 text-[9px] md:text-[10px]">
                                @forelse($recentProds as $prod)
                                    <tr class="hover:bg-slate-50 transition-colors font-bold italic">
                                        <td class="px-6 md:px-8 py-4 md:py-5">
                                            <p class="text-slate-900 uppercase tracking-tighter text-xs md:text-sm font-black m-0">{{ $prod->batch->code }}</p>
                                            <p class="text-[8px] md:text-[9px] text-slate-400 italic m-0 mt-1">{{ $prod->production_date->format('d M') }}</p>
                                        </td>
                                        <td class="px-2 md:px-4 py-4 md:py-5 text-center font-black text-slate-900 text-[10px] md:text-xs">{{ number_format($prod->total_eggs_collected) }} <small class="text-[7px] md:text-[8px] opacity-40">U</small></td>
                                        <td class="px-2 md:px-4 py-4 md:py-5 text-center">
                                            @if($prod->is_graded) 
                                                <span class="bg-emerald-50 text-emerald-600 px-2 md:px-3 py-1 md:py-1.5 rounded-lg md:rounded-xl text-[7px] md:text-[8px] font-black border border-emerald-100 italic">TRIÉ</span> 
                                            @else 
                                                <span class="bg-orange-50 text-orange-400 px-2 md:px-3 py-1 md:py-1.5 rounded-lg md:rounded-xl text-[7px] md:text-[8px] font-black border border-orange-100 animate-pulse italic">À TRIER</span> 
                                            @endif
                                        </td>
                                        <td class="px-6 md:px-8 py-4 md:py-5 text-right border-none">
                                            <div class="flex justify-end gap-2 md:gap-3">
                                                @can('M')
                                                    @if(!$prod->is_graded)
                                                        <a href="{{ route('egg-productions.edit', $prod->id) }}" class="w-7 h-7 md:w-9 md:h-9 rounded-lg md:rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center hover:bg-slate-800 hover:text-white transition-all shadow-sm no-underline"><i class="fa-solid fa-pen text-[10px] md:text-sm"></i></a>
                                                    @endif
                                                    <a href="{{ route('egg-productions.tri', $prod->id) }}" class="w-7 h-7 md:w-9 md:h-9 rounded-lg md:rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center hover:bg-blue-500 hover:text-white transition-all shadow-sm shadow-blue-100 no-underline"><i class="fa-solid fa-scale-balanced text-[10px] md:text-sm"></i></a>
                                                @endcan
                                                @can('S')
                                                    <form action="{{ route('egg-productions.destroy', $prod->id) }}" method="POST" onsubmit="return confirm('Attention : Annuler le flux ?')" class="m-0">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="w-7 h-7 md:w-9 md:h-9 text-red-200 hover:text-red-500 transition-colors flex items-center justify-center outline-none bg-transparent border-none cursor-pointer"><i class="fa-solid fa-trash-can text-[10px] md:text-sm"></i></button>
                                                    </form>
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="p-8 md:p-12 text-center text-slate-300 italic uppercase text-[9px] md:text-[10px] tracking-widest border-none">Aucune production</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- SORTIES --}}
                <div class="bg-white rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-xl shadow-slate-200/30 overflow-hidden italic">
                    <div class="p-6 md:p-8 border-b border-slate-50 bg-slate-50/20 flex flex-wrap gap-2 justify-between items-center italic">
                        <h4 class="text-xs md:text-sm font-black uppercase tracking-widest text-slate-800 leading-none italic m-0">📤 Sorties Magasin</h4>
                        <span class="px-2 py-1 bg-red-100 text-red-600 rounded-lg text-[7px] md:text-[8px] font-black uppercase italic">Flux Sortant</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[350px] md:min-w-0">
                            <tbody class="divide-y divide-slate-50 text-[9px] md:text-[10px]">
                                @forelse($recentMovements as $mov)
                                    <tr class="hover:bg-slate-50 transition-colors font-bold italic">
                                        <td class="px-6 md:px-8 py-4 md:py-5">
                                            <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-3">
                                                <span class="text-[9px] md:text-[10px] font-black uppercase text-slate-900 tracking-tight italic truncate max-w-[100px] md:max-w-none">
                                                    {{ \Illuminate\Support\Str::limit($mov->notes ?? 'EXPÉDITION', 15) }}
                                                </span>
                                                <span class="w-fit px-2 py-1 bg-slate-900 text-white rounded-lg text-[7px] md:text-[8px] font-black uppercase tracking-tighter italic">
                                                    {{ $mov->stock->item_name }}
                                                </span>
                                            </div>
                                            <p class="text-[7px] md:text-[8px] text-slate-400 mt-1 uppercase italic m-0">{{ $mov->created_at->diffForHumans() }}</p>
                                        </td>
                                        <td class="px-4 py-4 md:py-5 text-center text-red-600 font-black text-[10px] md:text-xs italic">
                                            - {{ number_format($mov->quantity * 30, 0, ',', ' ') }} <small class="text-[7px] md:text-[8px] opacity-40">U</small>
                                            <div class="text-[7px] md:text-[8px] text-slate-400 mt-1">{{ number_format($mov->quantity, 1) }} Alv.</div>
                                        </td>
                                        <td class="px-6 md:px-8 py-4 md:py-5 text-right italic border-none">
                                            <div class="w-7 h-7 md:w-9 md:h-9 rounded-lg md:rounded-xl bg-slate-50 flex items-center justify-center text-slate-300 ml-auto" title="{{ $mov->notes }}">
                                                <i class="fa-solid fa-circle-info text-[10px] md:text-xs"></i>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="p-8 md:p-12 text-center text-slate-300 italic uppercase text-[9px] md:text-[10px] tracking-widest border-none">Aucune sortie</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</x-app-layout>