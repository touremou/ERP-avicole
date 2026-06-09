<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div>
                <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    📉 Performance Technique & Viabilité
                </h2>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-3 italic leading-none">
                    Analyse des indices de consommation et taux de survie
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                {{-- Légende technique --}}
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-xl text-[8px] font-black uppercase italic shadow-sm">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span> Normal < 3%
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 bg-orange-50 text-orange-600 border border-orange-100 rounded-xl text-[8px] font-black uppercase italic shadow-sm">
                    <span class="w-1.5 h-1.5 bg-orange-500 rounded-full"></span> Alerte 3-5%
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 bg-rose-50 text-rose-600 border border-rose-100 rounded-xl text-[8px] font-black uppercase italic shadow-sm">
                    <span class="w-1.5 h-1.5 bg-rose-500 rounded-full"></span> Critique > 5%
                </div>
                <a href="{{ route('reports.index') }}" class="ml-4 group flex items-center gap-2 text-[10px] font-black text-slate-400 hover:text-slate-800 uppercase italic transition no-underline">
                    <i class="fa-solid fa-chevron-left group-hover:-translate-x-1 transition-transform"></i> Retour
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <div class="grid grid-cols-1 gap-12">
                @forelse($stats as $batch)
                <div class="bg-white rounded-[4rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all duration-500 relative overflow-hidden group">
                    
                    {{-- Filigrane de fond dynamique --}}
                    <div class="absolute -right-10 -top-10 opacity-[0.03] group-hover:opacity-[0.07] transition-opacity duration-700">
                        <i class="fa-solid fa-chart-line text-[15rem] -rotate-12"></i>
                    </div>

                    <div class="p-10 relative z-10 text-left">
                        {{-- HEADER DU LOT --}}
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-12 gap-6">
                            <div class="flex items-center gap-6">
                                <div class="w-20 h-20 bg-slate-900 rounded-[2rem] flex items-center justify-center text-white shadow-2xl shadow-slate-900/20 transform group-hover:rotate-3 transition-transform">
                                    <i class="fa-solid fa-box-archive text-3xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-3xl font-black text-slate-900 tracking-tighter leading-none mb-3 italic uppercase">
                                        {{ $batch['code'] }}
                                    </h3>
                                    <div class="flex items-center gap-3">
                                        <span class="px-3 py-1 bg-blue-600 text-white rounded-xl text-[9px] font-black uppercase italic shadow-lg shadow-blue-200">ÂGE : {{ $batch['age'] }} J</span>
                                        <span class="text-[10px] text-slate-400 font-black uppercase tracking-[0.2em] italic">BAT : {{ $batch['building'] }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-2">
                                <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest italic leading-none">Indicateur de Viabilité</p>
                                <span @class([
                                    'px-6 py-3 rounded-[1.5rem] text-[11px] font-black uppercase italic border-2 shadow-xl transition-all',
                                    'bg-rose-50 text-rose-600 border-rose-100 shadow-rose-100' => $batch['status'] === 'Critique',
                                    'bg-orange-50 text-orange-600 border-orange-100 shadow-orange-100' => $batch['status'] === 'Alerte',
                                    'bg-emerald-50 text-emerald-600 border-emerald-100 shadow-emerald-100' => $batch['status'] === 'Normal',
                                ])>
                                    <i class="fa-solid fa-circle text-[8px] mr-2 animate-pulse"></i> {{ $batch['status'] }}
                                </span>
                            </div>
                        </div>

                        {{-- GRILLE DE PERFORMANCE --}}
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6 mb-10">
                            
                            {{-- INDICE DE CONSOMMATION (FCR) --}}
                            <div class="bg-slate-50 p-8 rounded-[2.5rem] border border-slate-100 flex flex-col items-center justify-center text-center group/card hover:bg-slate-900 transition-all duration-500">
                                <p class="text-[9px] text-slate-400 uppercase mb-3 group-hover/card:text-slate-500 font-black">Indice (FCR)</p>
                                <p class="text-3xl font-black text-slate-900 group-hover/card:text-white transition-colors italic tracking-tighter">{{ number_format($batch['fcr'] ?? 0, 2) }}</p>
                                <p class="text-[8px] text-blue-500 font-black mt-2 uppercase italic tracking-widest group-hover/card:text-blue-400">Rendement Aliment</p>
                            </div>

                            {{-- POIDS MOYEN --}}
                            <div class="bg-slate-50 p-8 rounded-[2.5rem] border border-slate-100 flex flex-col items-center justify-center text-center">
                                <p class="text-[9px] text-slate-400 uppercase mb-3 font-black">Poids Moyen</p>
                                <p class="text-3xl font-black text-slate-900 italic tracking-tighter">{{ number_format($batch['avg_weight'] ?? 0, 0) }}<small class="text-xs uppercase ml-1">g</small></p>
                                <p class="text-[8px] text-emerald-500 font-black mt-2 uppercase italic tracking-widest">+{{ $batch['daily_gain'] ?? 0 }}g / Jour</p>
                            </div>

                            {{-- VIVANT ACTUEL --}}
                            <div class="bg-slate-50 p-8 rounded-[2.5rem] border border-slate-100 flex flex-col items-center justify-center text-center">
                                <p class="text-[9px] text-slate-400 uppercase mb-3 font-black">Stock Vivant</p>
                                <p class="text-3xl font-black text-blue-600 italic tracking-tighter">{{ number_format($batch['current']) }}</p>
                                <p class="text-[8px] text-slate-400 font-black mt-2 uppercase italic tracking-widest">Initial : {{ number_format($batch['initial']) }}</p>
                            </div>

                            {{-- PERTES (TÊTES) --}}
                            <div class="bg-slate-50 p-8 rounded-[2.5rem] border border-slate-100 flex flex-col items-center justify-center text-center">
                                <p class="text-[9px] text-rose-400 uppercase mb-3 font-black">Mortalité</p>
                                <p class="text-3xl font-black text-rose-600 italic tracking-tighter">{{ number_format($batch['mortality_count']) }}</p>
                                <p class="text-[8px] text-rose-400 font-black mt-2 uppercase italic tracking-widest">Sujets perdus</p>
                            </div>

                            {{-- TAUX DE MORTALITÉ (L) --}}
                            <div @class([
                                'p-8 rounded-[3rem] border-2 flex flex-col items-center justify-center text-center col-span-2 lg:col-span-2 shadow-inner',
                                'bg-rose-50 border-rose-100' => $batch['mortality_rate'] > 5,
                                'bg-orange-50 border-orange-100' => $batch['mortality_rate'] > 3 && $batch['mortality_rate'] <= 5,
                                'bg-emerald-50 border-emerald-100' => $batch['mortality_rate'] <= 3,
                            ])>
                                <p @class([
                                    'text-[10px] font-black uppercase mb-2 italic tracking-[0.2em]',
                                    'text-rose-800' => $batch['mortality_rate'] > 3,
                                    'text-emerald-800' => $batch['mortality_rate'] <= 3,
                                ])>
                                    Taux de Mortalité Cumulé
                                </p>
                                <div class="flex items-baseline gap-1">
                                    <p @class([
                                        'text-6xl font-black italic tracking-tighter',
                                        'text-rose-600' => $batch['mortality_rate'] > 5,
                                        'text-orange-600' => $batch['mortality_rate'] > 3 && $batch['mortality_rate'] <= 5,
                                        'text-emerald-600' => $batch['mortality_rate'] <= 3,
                                    ])>
                                        {{ number_format($batch['mortality_rate'], 2) }}%
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- VISUALISATION GRAPHIQUE (BARRE DE CHARGE) --}}
                        <div class="space-y-3">
                            <div class="flex justify-between items-center px-4">
                                <p class="text-[9px] font-black uppercase text-slate-400 italic">Progression des pertes vs seuil critique (10%)</p>
                                <p class="text-[9px] font-black text-slate-800 italic uppercase">Limite Technique</p>
                            </div>
                            <div class="relative h-6 bg-slate-50 rounded-full overflow-hidden border border-slate-100 p-1.5 shadow-inner">
                                <div class="h-full rounded-full transition-all duration-1000 shadow-sm {{ $batch['mortality_rate'] > 5 ? 'bg-rose-500' : ($batch['mortality_rate'] > 3 ? 'bg-orange-500' : 'bg-emerald-500') }}" 
                                     style="width: {{ min($batch['mortality_rate'] * 10, 100) }}%">
                                </div>
                                {{-- Marqueur de danger 5% --}}
                                <div class="absolute left-1/2 top-0 bottom-0 w-px bg-rose-200 border-r border-dashed border-rose-300"></div>
                            </div>
                        </div>
                    </div>

                    {{-- FOOTER / AUDIT --}}
                    <div class="bg-slate-50 px-10 py-6 flex justify-between items-center border-t border-slate-100 italic">
                        <div class="flex items-center gap-2">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                            </span>
                            <p class="text-[9px] text-slate-400 font-black uppercase">Calcul temps réel • {{ now()->translatedFormat('d F Y - H:i') }}</p>
                        </div>
                        <a href="{{ route('batches.show', $batch['id']) }}" class="group/btn flex items-center gap-3 px-6 py-3 bg-white border border-slate-200 text-slate-900 rounded-2xl text-[10px] font-black uppercase tracking-widest no-underline hover:bg-slate-900 hover:text-white transition-all duration-300">
                            Audit Complet du Lot <i class="fa-solid fa-arrow-right group-hover/btn:translate-x-2 transition-transform"></i>
                        </a>
                    </div>
                </div>
                @empty
                <div class="bg-white p-32 rounded-[5rem] text-center border-4 border-dashed border-slate-50 italic">
                    <div class="w-24 h-24 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-8 shadow-inner">
                        <i class="fa-solid fa-chart-area text-4xl text-slate-200"></i>
                    </div>
                    <p class="text-slate-300 uppercase text-xs font-black tracking-[0.4em] italic leading-none">Aucun lot actif en cours d'analyse</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>