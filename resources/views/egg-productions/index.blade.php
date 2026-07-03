<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🥚 Dashboard Production')" :subtitle="__('Gestion des Flux & Inventaire')" icon="fa-layer-group" accent="emerald">
            <x-slot name="actions">
                @can('production.C')
                <a href="{{ route('egg-productions.tour') }}" class="flex-1 md:flex-none text-center bg-emerald-500 text-white px-4 py-2 md:px-5 md:py-2.5 rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-emerald-400 transition-all shadow-lg no-underline">
                    <i class="fa-solid fa-route mr-1 md:mr-2 text-emerald-100"></i> {{ __("Tournée du jour") }}
                </a>
                @endcan
                @can('production.L')
                <a href="{{ route('stocks.index', ['category' => 'oeufs']) }}" class="flex-1 md:flex-none text-center bg-white border border-slate-200 text-slate-700 px-4 py-2 md:px-5 md:py-2.5 rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-slate-50 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-box-open mr-1 md:mr-2 text-emerald-500"></i> {{ __("Magasin") }}
                </a>
                <a href="{{ route('reports.index') }}" class="flex-1 md:flex-none text-center bg-white border border-slate-200 text-slate-700 px-4 py-2 md:px-5 md:py-2.5 rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-slate-50 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-chart-pie mr-1 md:mr-2 text-blue-500"></i> {{ __("Rapports") }}
                </a>
                @endcan

                {{-- Corrigé en oeufs.S pour correspondre à la sécurité du contrôleur --}}
                @can('production.S')
                <a href="{{ route('stocks.maintenance') }}"
                    class="w-full md:w-auto flex items-center justify-center gap-2 px-4 py-2 md:px-5 md:py-2.5 bg-slate-900 text-white rounded-xl md:rounded-2xl text-[9px] md:text-[10px] font-black uppercase italic hover:bg-red-600 transition-all shadow-xl shadow-slate-200 no-underline">
                        <i class="fa-solid fa-wrench text-red-400"></i> <span class="md:inline">{{ __("Inventaire") }}</span>
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-4 md:py-6 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5 md:space-y-6">

            {{-- SECTION 1 : KPI DE STOCK --}}
            <div class="-mx-4 px-4 sm:mx-0 sm:px-0">
                <div class="flex md:grid md:grid-cols-5 gap-3 md:gap-4 overflow-x-auto pb-2 snap-x snap-mandatory md:overflow-visible md:pb-0 [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]">
                    
                    {{-- RECOLTE DU JOUR --}}
                    <div class="min-w-[240px] md:min-w-0 snap-center bg-slate-900 p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] text-white shadow-xl relative overflow-hidden group text-left border-none">
                        <p class="text-[9px] md:text-[10px] font-black text-slate-500 uppercase mb-1 tracking-widest italic leading-none m-0">{{ __("Flux Entrant (Brut)") }}</p>
                        <h3 class="text-3xl md:text-4xl font-black text-white leading-none tracking-tighter m-0 mt-2">{{ number_format($totalEggsToday) }}</h3>
                        <div class="mt-3 flex items-center gap-2 text-[7px] md:text-[8px] uppercase font-black text-emerald-400/80 bg-emerald-500/10 w-fit px-2.5 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> {{ __("Collecte Jour") }}
                        </div>
                        <i class="fa-solid fa-arrow-right-to-bracket absolute -right-3 -bottom-3 text-white/5 text-5xl md:text-6xl"></i>
                    </div>

                    {{-- RESERVE BRUTE --}}
                    <div class="min-w-[240px] md:min-w-0 snap-center bg-white p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] border border-orange-100 shadow-lg shadow-orange-500/5 relative group text-left">
                        <div class="absolute top-4 right-4 md:top-5 md:right-5 px-2 py-0.5 md:px-2.5 md:py-1 bg-orange-100 text-orange-600 text-[7px] md:text-[8px] font-black rounded-lg italic uppercase tracking-tighter">{{ __("Attente Tri") }}</div>
                        <p class="text-[9px] md:text-[10px] font-black text-orange-400 uppercase mb-1 tracking-widest italic leading-none m-0">{{ __("Réserve Brute") }}</p>
                        <h3 class="text-3xl md:text-4xl font-black text-slate-800 tracking-tighter m-0 mt-2">{{ number_format($stockNonTrie) }}</h3>
                        <p class="text-[7px] md:text-[8px] text-slate-400 mt-2 uppercase italic leading-none m-0">{{ __("Unités non calibrées") }}</p>
                    </div>

                    {{-- STOCK MAGASIN CALIBRÉ --}}
                    <div class="min-w-[240px] md:min-w-0 snap-center bg-emerald-500 p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] text-white shadow-xl shadow-emerald-500/20 relative group text-left overflow-hidden border-none">
                        <p class="text-[9px] md:text-[10px] font-black text-emerald-100 uppercase mb-1 tracking-widest italic leading-none m-0">{{ __("Stock Magasin") }}</p>
                        <h3 class="text-3xl md:text-4xl font-black tracking-tighter m-0 mt-2">{{ number_format(array_sum($stockVendable), 2) }}</h3>
                        <p class="text-[8px] md:text-[9px] text-emerald-50 mt-2 md:mt-3 uppercase italic font-black tracking-tighter m-0">{{ __("Alvéoles (S-XL)") }}</p>
                        
                        <div class="mt-2 md:mt-3 flex flex-wrap gap-1 opacity-100 md:opacity-80 md:group-hover:opacity-100 transition-opacity">
                            @foreach($stockVendable as $grade => $qty)
                                <span class="text-[6px] md:text-[7px] font-black uppercase bg-white/20 px-1.5 py-0.5 rounded border border-white/10 text-white">{{ strtoupper($grade) }}: {{ number_format($qty, 1) }}</span>
                            @endforeach
                        </div>
                        <i class="fa-solid fa-warehouse absolute -right-4 -bottom-4 text-white/10 text-6xl md:text-7xl"></i>
                    </div>
                    
                    {{-- PERTES --}}
                    <div class="min-w-[240px] md:min-w-0 snap-center bg-red-500 p-5 md:p-6 rounded-[1.5rem] md:rounded-[2rem] text-white shadow-xl shadow-red-500/20 relative group text-left overflow-hidden border-none">
                        <p class="text-[9px] md:text-[10px] font-black text-red-100 uppercase mb-1 tracking-widest italic leading-none m-0">{{ __("Pertes en Stock") }}</p>
                        <h3 class="text-3xl md:text-4xl font-black m-0 mt-2">
                            @php 
                                $itemCasse = $stockItems->where('item_name', 'Cassé')->first();
                                $itemAnomalie = $stockItems->where('item_name', 'Anomalie')->first();
                                $totalPertesAlv = ($itemCasse->current_quantity ?? 0) + ($itemAnomalie->current_quantity ?? 0);
                            @endphp
                            {{ number_format($totalPertesAlv, 2) }}
                        </h3>
                        <p class="text-[7px] md:text-[8px] text-red-50 mt-2 uppercase italic leading-none font-black tracking-tighter m-0">
                            ≈ {{ round($totalPertesAlv * setting('general.eggs_per_tray', 30)) }} {{ __("Œufs (Pertes)") }}
                        </p>
                        <i class="fa-solid fa-dumpster absolute -right-4 -bottom-4 text-white/10 text-5xl md:text-6xl"></i>
                    </div>

                    {{-- REPARTITION GRAPHIQUE --}}
                    <div class="min-w-[240px] md:min-w-0 snap-center bg-white p-5 rounded-[1.5rem] md:rounded-[2rem] border border-slate-100 flex flex-col justify-center gap-1.5 md:gap-2 shadow-sm relative overflow-hidden">
                        <div class="absolute -right-4 -top-4 w-12 h-12 bg-slate-50 rounded-full"></div>
                        @foreach(\App\Models\EggProduction::activeGrades() as $grade => $meta)
                            @php $val = $stockVendable[strtolower($grade)] ?? 0; @endphp
                            <div class="flex items-center justify-between group relative z-10">
                                <span class="text-[9px] font-black uppercase text-{{ $meta['color'] }}-500 italic">{{ $grade }}</span>
                                <div class="flex-1 mx-2 h-[1px] bg-slate-100 border-b border-dashed border-slate-200"></div>
                                <span class="text-[9px] md:text-[10px] font-black text-slate-700 tracking-tighter">{{ number_format($val, 1) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- SECTION 2 : SUIVI TECHNIQUE PAR LOT --}}
            <div class="bg-white rounded-[1.5rem] md:rounded-[2rem] border border-slate-100 shadow-xl shadow-slate-200/40 overflow-hidden text-left">
                <div class="p-4 md:p-6 border-b border-slate-50 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 bg-slate-50/30">
                    <div class="flex items-center gap-3">
                        <div class="w-1.5 h-6 md:h-8 bg-emerald-500 rounded-full"></div>
                        <div>
                            <h4 class="text-xs md:text-sm font-black uppercase tracking-tight text-slate-800 italic leading-none m-0">{{ __("📊 Performance Technique") }}</h4>
                            <p class="text-[8px] text-slate-400 font-bold uppercase tracking-widest mt-1 md:mt-1.5 italic leading-none m-0">{{ __("Indice HDP & Collecte") }}</p>
                        </div>
                    </div>
                    <div class="bg-slate-900 text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg text-[8px] font-black uppercase tracking-[0.2em] italic">
                        {{ __("LIVE") }} : {{ now()->format('H:i') }}
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[600px] md:min-w-0">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic bg-slate-50/80">
                                <th class="px-4 md:px-6 py-3">{{ __("Lot / Bâtiment") }}</th>
                                <th class="px-3 py-3 text-center hidden sm:table-cell">{{ __("Âge") }}</th>
                                <th class="px-3 py-3 text-center">{{ __("Récolte Jour") }}</th>
                                <th class="px-3 py-3 text-center">{{ __("Laying Rate (HDP)") }}</th>
                                <th class="px-4 md:px-6 py-3 text-right">{{ __("Actions") }}</th>
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
                                    $currentWeek = ceil($b->age / 7);
                                    $peakWeek = (int) setting('production.peak_laying_week', 28);
                                    $weekDiff = $currentWeek - $peakWeek;
                                @endphp
                                <tr class="hover:bg-slate-50/80 transition-all font-bold group">
                                    <td class="px-4 md:px-6 py-3 md:py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400 text-xs shadow-inner group-hover:bg-emerald-500 group-hover:text-white transition-all flex-shrink-0">
                                                <i class="fa-solid fa-house-crack"></i>
                                            </div>
                                            <div>
                                                <p class="text-xs md:text-sm font-black text-slate-800 uppercase tracking-tighter leading-none m-0">{{ $b->code }}</p>
                                                <p class="text-[8px] text-blue-500 uppercase mt-1 tracking-widest italic m-0">{{ $b->building->name }} <span class="sm:hidden text-slate-400">• S-{{ $currentWeek }}</span></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-2.5">
                                            @for($i=0; $i<setting('production.max_passages', 4); $i++)
                                                @if($i < $passagesCount)
                                                    @if($i == $passagesCount - 1 && auth()->user()->can('production.M')) 
                                                        <a href="{{ route('egg-productions.tri', $todayRecord->id) }}" 
                                                           @class(['w-5 h-1.5 md:w-6 md:h-2 rounded-full transition-all hover:scale-110 shadow-sm', 'bg-emerald-500 shadow-emerald-200' => $isGraded, 'bg-orange-400 animate-pulse shadow-orange-200' => !$isGraded]) 
                                                           title="{{ $isGraded ? __("Trié (Modifier)") : __("Trier le cumul") }}"></a>
                                                    @else
                                                        <div @class(['w-5 h-1.5 md:w-6 md:h-2 rounded-full shadow-sm', 'bg-emerald-500' => $isGraded, 'bg-orange-400' => !$isGraded])></div>
                                                    @endif
                                                @else
                                                    <div class="w-5 h-1.5 md:w-6 md:h-2 rounded-full bg-slate-100"></div>
                                                @endif
                                            @endfor
                                            <span class="text-[7px] md:text-[8px] text-slate-400 uppercase ml-1.5 italic font-black hidden sm:inline">{{ $passagesCount }}/{{ setting('production.max_passages', 4) }} {{ __("Passages") }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 md:py-4 text-center text-[9px] md:text-[10px] text-slate-500 italic hidden sm:table-cell">
                                        S-{{ $currentWeek }}
                                        <span @class(['block text-[7px] font-black uppercase tracking-widest mt-0.5 not-italic',
                                            'text-blue-400' => $weekDiff < -2,
                                            'text-emerald-500' => abs($weekDiff) <= 2,
                                            'text-amber-500' => $weekDiff > 2])
                                            title="{{ __("Pic de ponte attendu") }} : S-{{ $peakWeek }}">
                                            @if($weekDiff < -2) {{ __("Montée") }}
                                            @elseif(abs($weekDiff) <= 2) {{ __("Pic") }}
                                            @else {{ __("Post-pic") }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 md:py-4 text-center text-[11px] md:text-xs text-slate-900 font-black italic">{{ number_format($totalDayEggs) }} <small class="text-[7px] opacity-40 uppercase hidden sm:inline">{{ __("Unités") }}</small></td>
                                    <td class="px-3 py-3 md:py-4 text-center">
                                        <div class="flex flex-col items-center gap-1.5">
                                            <span @class(['px-2 md:px-3 py-1 rounded-full text-[8px] md:text-[9px] font-black italic shadow-sm', 
                                                'bg-emerald-50 text-emerald-600' => $hdp >= setting('production.hdp_target', 80), 
                                                'bg-orange-50 text-orange-600' => $hdp >= setting('production.hdp_alert_low', 50) && $hdp < setting('production.hdp_target', 80), 
                                                'bg-red-50 text-red-600' => $hdp < setting('production.hdp_target', 80) && $hdp > 0, 
                                                'bg-slate-100 text-slate-400' => $hdp == 0])>{{ $hdp }}%</span>
                                            <div class="w-10 md:w-12 h-1 bg-slate-100 rounded-full overflow-hidden shadow-inner hidden sm:block">
                                                <div @class(['h-full transition-all duration-700', 'bg-emerald-500' => $hdp >= setting('production.hdp_target', 80), 'bg-orange-400' => $hdp < setting('production.hdp_target', 80) && $hdp >= setting('production.hdp_alert_low', 50), 'bg-red-500' => $hdp < setting('production.hdp_target', 80)]) style="width: {{ $hdp }}%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 md:px-6 py-3 md:py-4 text-right">
                                        @can('production.C')
                                            @if(! $b->canCollectEggs())
                                                {{-- Garde-fou zootechnique : lot pas encore en âge de pondre --}}
                                                <span class="px-3 py-1.5 md:py-2 bg-amber-50 text-amber-600 border border-amber-100 rounded-lg md:rounded-xl text-[8px] md:text-[9px] font-black uppercase shadow-sm inline-flex items-center gap-1.5 italic cursor-default whitespace-nowrap" title="{{ __('Phase') }} : {{ $b->current_phase }} — {{ __('entrée en ponte vers') }} {{ (int) ceil($b->minLayingAgeDays() / 7) }} {{ __('sem.') }}">
                                                    <i class="fa-solid fa-hourglass-half text-[10px]"></i> <span class="hidden sm:inline">{{ __("Pas en âge") }}</span>
                                                </span>
                                            @elseif(!$isGraded && $passagesCount < setting('production.max_passages', 4))
                                                <a href="{{ route('egg-productions.create', ['batch_id' => $b->id]) }}" class="px-3 py-1.5 md:py-2 bg-slate-900 text-white rounded-lg md:rounded-xl text-[8px] md:text-[9px] font-black uppercase shadow-lg shadow-slate-200 hover:bg-emerald-600 transition-all inline-flex items-center gap-1.5 italic no-underline whitespace-nowrap">
                                                    <i class="fa-solid fa-plus text-emerald-400 text-[10px]"></i> <span class="hidden sm:inline">{{ __("Nouveau Passage") }}</span><span class="sm:hidden">{{ __("Collecter") }}</span>
                                                </a>
                                            @elseif($isGraded)
                                                <span class="px-3 py-1.5 md:py-2 bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-lg md:rounded-xl text-[8px] md:text-[9px] font-black uppercase shadow-sm inline-flex items-center gap-1.5 italic cursor-default whitespace-nowrap">
                                                    <i class="fa-solid fa-check-double text-[10px]"></i> <span class="hidden sm:inline">{{ __("Clôturé") }}</span>
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

            {{-- SECTION 3 : FLUX RECENTS --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 md:gap-6 text-left">
                
                {{-- ENTRÉES --}}
                <div class="bg-white rounded-[1.5rem] md:rounded-[2rem] border border-slate-100 shadow-xl shadow-slate-200/30 overflow-hidden relative">
                    <div class="p-4 md:p-5 border-b border-slate-50 bg-slate-50/20 flex flex-wrap gap-2 justify-between items-center italic">
                        <h4 class="text-[10px] md:text-xs font-black uppercase tracking-widest text-slate-800 leading-none italic m-0">{{ __("📅 Entrées Brutes") }}</h4>
                        <span class="px-2 py-0.5 md:py-1 bg-emerald-100 text-emerald-600 rounded-md md:rounded-lg text-[7px] font-black uppercase italic">{{ __("Flux Entrant") }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[350px] md:min-w-0">
                            <tbody class="divide-y divide-slate-50 text-[9px]">
                                @forelse($recentProds as $prod)
                                    <tr class="hover:bg-slate-50 transition-colors font-bold italic">
                                        <td class="px-4 md:px-5 py-2.5 md:py-3">
                                            <p class="text-slate-900 uppercase tracking-tighter text-[10px] md:text-xs font-black m-0">{{ $prod->batch->code }}</p>
                                            <p class="text-[7px] md:text-[8px] text-slate-400 italic m-0 mt-0.5">{{ $prod->production_date->format('d M') }}</p>
                                        </td>
                                        <td class="px-2 py-2.5 md:py-3 text-center font-black text-slate-900 text-[9px] md:text-[10px]">{{ number_format($prod->total_eggs_collected) }} <small class="text-[7px] opacity-40">U</small></td>
                                        <td class="px-2 py-2.5 md:py-3 text-center">
                                            @if($prod->is_graded) 
                                                <span class="bg-emerald-50 text-emerald-600 px-2 py-1 rounded-md md:rounded-lg text-[7px] font-black border border-emerald-100 italic">{{ __("TRIÉ") }}</span>
                                            @else
                                                <span class="bg-orange-50 text-orange-400 px-2 py-1 rounded-md md:rounded-lg text-[7px] font-black border border-orange-100 animate-pulse italic">{{ __("À TRIER") }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 md:px-5 py-2.5 md:py-3 text-right border-none">
                                            <div class="flex justify-end gap-1.5 md:gap-2">
                                                <a href="{{ route('egg-productions.label', $prod->id) }}" target="_blank" title="{{ __('Étiquette QR de traçabilité') }}" class="w-6 h-6 md:w-7 md:h-7 rounded-md md:rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center hover:bg-amber-500 hover:text-white transition-all shadow-sm no-underline"><i class="fa-solid fa-qrcode text-[8px] md:text-[9px]"></i></a>
                                                @can('production.M')
                                                    @if(!$prod->is_graded)
                                                        <a href="{{ route('egg-productions.edit', $prod->id) }}" class="w-6 h-6 md:w-7 md:h-7 rounded-md md:rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center hover:bg-slate-800 hover:text-white transition-all shadow-sm no-underline"><i class="fa-solid fa-pen text-[8px] md:text-[9px]"></i></a>
                                                    @endif
                                                    <a href="{{ route('egg-productions.tri', $prod->id) }}" class="w-6 h-6 md:w-7 md:h-7 rounded-md md:rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center hover:bg-blue-500 hover:text-white transition-all shadow-sm shadow-blue-100 no-underline"><i class="fa-solid fa-scale-balanced text-[8px] md:text-[9px]"></i></a>
                                                @endcan
                                                @can('production.S')
                                                    <form action="{{ route('egg-productions.destroy', $prod->id) }}" method="POST" onsubmit="return confirm(@json(__('Attention : Annuler le flux ?')))" class="m-0">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="w-6 h-6 md:w-7 md:h-7 text-red-200 hover:text-red-500 transition-colors flex items-center justify-center outline-none bg-transparent border-none cursor-pointer"><i class="fa-solid fa-trash-can text-[8px] md:text-[9px]"></i></button>
                                                    </form>
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="p-6 md:p-8 text-center text-slate-300 italic uppercase text-[8px] md:text-[9px] tracking-widest border-none">{{ __("Aucune production") }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- SORTIES --}}
                <div class="bg-white rounded-[1.5rem] md:rounded-[2rem] border border-slate-100 shadow-xl shadow-slate-200/30 overflow-hidden italic">
                    <div class="p-4 md:p-5 border-b border-slate-50 bg-slate-50/20 flex flex-wrap gap-2 justify-between items-center italic">
                        <h4 class="text-[10px] md:text-xs font-black uppercase tracking-widest text-slate-800 leading-none italic m-0">{{ __("📤 Sorties Magasin") }}</h4>
                        <span class="px-2 py-0.5 md:py-1 bg-red-100 text-red-600 rounded-md md:rounded-lg text-[7px] font-black uppercase italic">{{ __("Flux Sortant") }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[300px] md:min-w-0">
                            <tbody class="divide-y divide-slate-50 text-[9px]">
                                @forelse($recentMovements as $mov)
                                    <tr class="hover:bg-slate-50 transition-colors font-bold italic">
                                        <td class="px-4 md:px-5 py-2.5 md:py-3">
                                            <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-2">
                                                <span class="text-[8px] md:text-[9px] font-black uppercase text-slate-900 tracking-tight italic truncate max-w-[100px] md:max-w-none">
                                                    {{ \Illuminate\Support\Str::limit($mov->notes ?? __('EXPÉDITION'), 15) }}
                                                </span>
                                                <span class="w-fit px-1.5 py-0.5 bg-slate-900 text-white rounded-md text-[6px] md:text-[7px] font-black uppercase tracking-tighter italic">
                                                    {{ $mov->stock->item_name }}
                                                </span>
                                            </div>
                                            <p class="text-[6px] md:text-[7px] text-slate-400 mt-1 uppercase italic m-0">{{ $mov->created_at->diffForHumans() }}</p>
                                        </td>
                                        <td class="px-3 py-2.5 md:py-3 text-center text-red-600 font-black text-[9px] md:text-[10px] italic">
                                            - {{ number_format($mov->quantity * setting('general.eggs_per_tray', 30), 0, ',', ' ') }} <small class="text-[7px] opacity-40">U</small>
                                            <div class="text-[6px] md:text-[7px] text-slate-400 mt-0.5">{{ number_format($mov->quantity, 1) }} {{ __("Alv.") }}</div>
                                        </td>
                                        <td class="px-4 md:px-5 py-2.5 md:py-3 text-right italic border-none">
                                            <div class="w-6 h-6 md:w-7 md:h-7 rounded-md md:rounded-lg bg-slate-50 flex items-center justify-center text-slate-300 ml-auto" title="{{ $mov->notes }}">
                                                <i class="fa-solid fa-circle-info text-[8px] md:text-[9px]"></i>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="p-6 md:p-8 text-center text-slate-300 italic uppercase text-[8px] md:text-[9px] tracking-widest border-none">{{ __("Aucune sortie") }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</x-app-layout>