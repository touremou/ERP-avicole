<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div>
                <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    📊 Flux Opérationnels & Coûts
                </h2>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-3 italic leading-none">
                    Audit de trésorerie mensuel : Alimentation & Santé
                </p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                {{-- Filtre Statut (L) --}}
                <div class="relative">
                    <select onchange="window.location.href=this.value" class="appearance-none pl-4 pr-10 py-3 bg-white border border-slate-200 rounded-xl text-[10px] font-black uppercase italic shadow-sm focus:ring-4 focus:ring-blue-500/10 transition-all cursor-pointer">
                        <option value="{{ request()->fullUrlWithQuery(['status' => 'all']) }}">Tous les statuts</option>
                        <option value="{{ request()->fullUrlWithQuery(['status' => 'actif']) }}" {{ $statusFilter == 'actif' ? 'selected' : '' }}>🟢 Actifs</option>
                        <option value="{{ request()->fullUrlWithQuery(['status' => 'termine']) }}" {{ $statusFilter == 'termine' ? 'selected' : '' }}>🔵 Terminés</option>
                    </select>
                </div>

                {{-- Filtre Mois (L) --}}
                <div class="relative">
                    <select onchange="window.location.href=this.value" class="appearance-none pl-4 pr-10 py-3 bg-slate-900 text-white border-none rounded-xl text-[10px] font-black uppercase italic shadow-lg focus:ring-4 focus:ring-blue-500/10 transition-all cursor-pointer">
                        <option value="{{ request()->fullUrlWithQuery(['month' => 'all']) }}">Toute l'année</option>
                        @foreach($months as $num => $name)
                            <option value="{{ request()->fullUrlWithQuery(['month' => $num]) }}" {{ $monthFilter == $num ? 'selected' : '' }}>{{ strtoupper($name) }}</option>
                        @endforeach
                    </select>
                </div>

                <a href="{{ route('reports.index') }}" class="flex items-center justify-center w-11 h-11 bg-white border border-slate-200 text-slate-400 hover:text-rose-600 rounded-xl transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-16">
            @if(empty($monthlyData))
                <div class="py-40 text-center flex flex-col items-center justify-center">
                    <div class="w-20 h-20 bg-slate-50 rounded-[2.5rem] flex items-center justify-center text-slate-200 mb-6">
                        <i class="fa-solid fa-chart-pie text-4xl"></i>
                    </div>
                    <p class="text-slate-400 uppercase text-xs font-black tracking-[0.3em] italic">Aucun flux financier indexé</p>
                </div>
            @else
                @foreach($months as $num => $name)
                    @if(isset($monthlyData[$num]))
                        <div class="relative pl-12 border-l-4 border-slate-100 pb-20 last:pb-0 text-left">
                            {{-- Indicateur temporel --}}
                            <div class="absolute -left-[15px] top-0 w-7 h-7 bg-blue-600 rounded-full border-4 border-white shadow-xl shadow-blue-200 animate-pulse"></div>
                            
                            <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-12 gap-4">
                                <div>
                                    <h3 class="text-4xl font-black text-slate-900 uppercase tracking-tighter leading-none italic">{{ $name }}</h3>
                                    <p class="text-[10px] text-blue-500 uppercase mt-2 font-black tracking-widest italic leading-none">Période d'exploitation opérationnelle</p>
                                </div>
                                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-xl text-right">
                                    <p class="text-[9px] text-slate-400 uppercase mb-2 tracking-[0.2em] font-black italic">Charges Consolidées du Mois</p>
                                    <p class="text-3xl font-black text-slate-900 tracking-tighter italic">
                                        {{ number_format(collect($monthlyData[$num])->sum('health') + collect($monthlyData[$num])->sum('feed_cost'), 0, ',', ' ') }} <small class="text-xs uppercase opacity-40">GNF</small>
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10">
                                @foreach($monthlyData[$num] as $batchId => $data)
                                    <div class="bg-white rounded-[3.5rem] border border-slate-50 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 group overflow-hidden relative">
                                        <div class="p-10">
                                            <div class="flex justify-between items-start mb-10">
                                                <div>
                                                    <p class="text-xl font-black text-slate-800 leading-none uppercase tracking-tighter italic">{{ $data['batch']->code }}</p>
                                                    <p class="text-[9px] text-slate-400 mt-2 uppercase font-black tracking-widest italic">{{ $data['batch']->building->name ?? 'ZONE LIBRE' }}</p>
                                                </div>
                                                <span @class([
                                                    'px-4 py-1.5 rounded-xl text-[8px] font-black uppercase italic shadow-sm',
                                                    'bg-emerald-50 text-emerald-600 border border-emerald-100' => $data['batch']->status === 'Actif',
                                                    'bg-blue-50 text-blue-600 border border-blue-100' => $data['batch']->status === 'Terminé',
                                                ])>
                                                    {{ $data['batch']->status }}
                                                </span>
                                            </div>

                                            <div class="space-y-4">
                                                {{-- SANTÉ --}}
                                                <div class="flex justify-between items-center bg-slate-50 p-5 rounded-[1.5rem] border border-slate-100 group-hover:bg-white transition-colors">
                                                    <div class="flex items-center gap-3">
                                                        <i class="fa-solid fa-pills text-rose-500 opacity-50"></i>
                                                        <span class="text-[10px] font-black uppercase text-slate-400 italic">Invest. Santé</span>
                                                    </div>
                                                    <span class="text-base font-black text-rose-600 italic">{{ number_format($data['health'] ?? 0, 0, ',', ' ') }} <small class="text-[8px] opacity-60">GNF</small></span>
                                                </div>

                                                {{-- ALIMENT --}}
                                                <div class="bg-slate-50 p-5 rounded-[1.5rem] border border-slate-100 group-hover:bg-white transition-colors space-y-3">
                                                    <div class="flex justify-between items-center">
                                                        <div class="flex items-center gap-3">
                                                            <i class="fa-solid fa-wheat-awn text-orange-500 opacity-50"></i>
                                                            <span class="text-[10px] font-black uppercase text-slate-400 italic">Charge Aliment</span>
                                                        </div>
                                                        <span class="text-base font-black text-orange-600 italic">{{ number_format($data['feed_cost'] ?? 0, 0, ',', ' ') }} <small class="text-[8px] opacity-60">GNF</small></span>
                                                    </div>
                                                    <div class="flex justify-between items-center px-2 py-2 bg-white/50 rounded-lg border border-white">
                                                        <span class="text-[8px] text-slate-400 italic uppercase font-black">Consommation brute</span>
                                                        <span class="text-[9px] font-black text-slate-600 uppercase">{{ number_format($data['feed_qty'] ?? 0, 1) }} KG</span>
                                                    </div>
                                                </div>
                                            </div>

                                            {{-- TOTAL DÉCAISSEMENT --}}
                                            <div class="mt-10 pt-8 border-t-2 border-dashed border-slate-100 flex justify-between items-end">
                                                <div class="text-left">
                                                    <p class="text-[10px] uppercase text-slate-900 font-black italic leading-none mb-2">Total Décaissement</p>
                                                    <p class="text-[8px] text-slate-300 font-black uppercase tracking-widest">Sur cette période</p>
                                                </div>
                                                <p class="text-2xl font-black text-slate-900 tracking-tighter italic">
                                                    {{ number_format(($data['health'] ?? 0) + ($data['feed_cost'] ?? 0), 0, ',', ' ') }} <small class="text-[10px] opacity-40">GNF</small>
                                                </p>
                                            </div>
                                        </div>
                                        
                                        {{-- Lien vers détails (L) --}}
                                        <a href="{{ route('batches.show', $data['batch']->id) }}" class="block w-full py-4 bg-slate-900 text-white text-center text-[9px] font-black uppercase tracking-[0.3em] italic hover:bg-blue-600 transition-all no-underline">
                                            Analyser les détails du lot
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif

        </div>
    </div>
</x-app-layout>