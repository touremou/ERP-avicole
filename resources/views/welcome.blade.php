@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto pb-12 italic font-bold">
    
    {{-- HEADER SECTION --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-12 gap-6 px-4 md:px-0">
        <div class="text-left">
            <span class="text-yellow-500 font-black uppercase text-[10px] tracking-[0.4em] mb-2 block">AviSmart Enterprise OS</span>
            <h1 class="text-5xl md:text-6xl font-black text-slate-900 tracking-tighter leading-none">
                {{ __("Bonjour,") }} <span class="text-slate-400">{{ __("Admin") }}</span>
            </h1>
            <p class="text-slate-500 text-sm mt-3 font-black uppercase tracking-widest italic opacity-70">
                {{ __("État du complexe au") }} {{ now()->translatedFormat('d F Y') }} • <span class="text-blue-500">{{ __("Flux Temps Réel") }}</span>
            </p>
        </div>
        
        <div class="flex items-center gap-4 bg-white p-2 rounded-[1.5rem] shadow-sm border border-slate-100">
            <div class="flex items-center gap-3 px-4 py-2 bg-slate-50 rounded-xl">
                <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full animate-pulse shadow-[0_0_10px_rgba(16,185,129,0.6)]"></div>
                <span class="text-[9px] font-black uppercase tracking-[0.1em] text-slate-600 italic">{{ __("Noyau Système Actif") }}</span>
            </div>
        </div>
    </div>

    {{-- TOP KPI GRID --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
        
        {{-- SUJETS VIVANTS --}}
        <div class="group bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 relative overflow-hidden">
            <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:bg-blue-600 group-hover:text-white transition-all duration-500 shadow-inner">
                <i class="fas fa-dove group-hover:scale-110 transition-transform"></i>
            </div>
            <p class="text-slate-400 text-[9px] font-black uppercase tracking-widest mb-1 italic leading-none">{{ __("Effectif Global Vivant") }}</p>
            <p class="text-4xl font-black text-slate-900 tracking-tighter italic">{{ number_format($totalSujets) }}</p>
            <div class="mt-4 flex items-center text-[10px] font-black text-emerald-500 uppercase italic">
                <i class="fas fa-arrow-trend-up mr-2"></i> +2.4% <span class="text-slate-300 ml-2 font-bold tracking-normal">{{ __("vs cycle précédent") }}</span>
            </div>
        </div>

        {{-- OCCUPATION --}}
        <div class="group bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 relative overflow-hidden">
            <div class="w-14 h-14 bg-orange-50 text-orange-600 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:bg-orange-600 group-hover:text-white transition-all duration-500 shadow-inner">
                <i class="fas fa-warehouse"></i>
            </div>
            <p class="text-slate-400 text-[9px] font-black uppercase tracking-widest mb-1 italic leading-none">{{ __("Charge d'Occupation") }}</p>
            <p class="text-4xl font-black text-slate-900 tracking-tighter italic">
                {{ $occupiedBuildings }}<span class="text-xl text-slate-300 mx-1">/</span><span class="text-2xl">{{ $totalBuildings }}</span>
            </p>
            <div class="mt-5 w-full bg-slate-100 h-2 rounded-full overflow-hidden border border-slate-50">
                <div class="bg-orange-500 h-full rounded-full transition-all duration-1000 shadow-[0_0_8px_rgba(249,115,22,0.4)]" style="width: {{ ($occupiedBuildings/$totalBuildings)*100 }}%"></div>
            </div>
        </div>

        {{-- VALEUR STOCK --}}
        <div class="group bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 relative overflow-hidden">
            <div class="w-14 h-14 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:bg-emerald-600 group-hover:text-white transition-all duration-500 shadow-inner">
                <i class="fas fa-coins"></i>
            </div>
            <p class="text-slate-400 text-[9px] font-black uppercase tracking-widest mb-1 italic leading-none">{{ __("Estimation Actif Cir.") }}</p>
            <p class="text-3xl font-black text-slate-900 tracking-tighter italic">
                {{ number_format($stockValue) }} <small class="text-xs font-black text-slate-400">{{ currency() }}</small>
            </p>
            <p class="mt-4 text-[9px] font-black text-slate-300 uppercase italic tracking-widest border-t border-slate-50 pt-3">{{ __("Cotation Marché Actuelle") }}</p>
        </div>

        {{-- ACTION NOUVELLE BANDE --}}
        <a href="{{ route('batches.create') }}" class="group bg-slate-900 p-8 rounded-[3rem] shadow-2xl shadow-slate-200 flex flex-col justify-between hover:bg-blue-600 transition-all duration-500 transform hover:scale-[1.02] no-underline border-none cursor-pointer relative overflow-hidden">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/5 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div class="w-14 h-14 bg-white/10 text-white rounded-2xl flex items-center justify-center text-2xl group-hover:bg-white group-hover:text-blue-600 transition-all shadow-lg relative z-10">
                <i class="fas fa-plus"></i>
            </div>
            <div class="relative z-10">
                <p class="text-slate-500 group-hover:text-white/70 text-[10px] font-black uppercase tracking-widest mb-1 italic leading-none">{{ __("Lancement Cycle") }}</p>
                <p class="text-2xl font-black text-white uppercase italic tracking-tighter">{{ __("Nouvelle Bande") }}</p>
            </div>
        </a>
    </div>

    {{-- MAIN CONTENT GRID --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 text-left">
        
        {{-- SECTION BANDES RÉCENTES --}}
        <div class="lg:col-span-2 bg-white rounded-[3.5rem] border border-slate-100 shadow-sm p-10">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
                <h3 class="text-2xl font-black text-slate-900 flex items-center uppercase tracking-tighter italic leading-none">
                    <span class="w-2.5 h-8 bg-blue-600 rounded-full mr-4 shadow-[0_0_10px_rgba(37,99,235,0.3)]"></span>
                    {{ __("Suivi Opérationnel Production") }}
                </h3>
                <a href="{{ route('batches.index') }}" class="px-6 py-2.5 bg-slate-50 text-[10px] font-black text-slate-500 uppercase tracking-widest hover:bg-slate-900 hover:text-white rounded-xl transition-all no-underline italic">{{ __("Explorer tout") }}</a>
            </div>
            
            <div class="space-y-4">
                @forelse($recentBatches as $batch)
                    <a href="{{ route('batches.show', $batch->id) }}" class="flex items-center p-6 bg-slate-50/50 rounded-[2.5rem] hover:bg-white hover:shadow-2xl hover:shadow-blue-500/10 hover:-translate-y-1 transition-all border border-transparent hover:border-blue-100 group no-underline relative">
                        <div class="w-16 h-16 bg-white rounded-2xl shadow-sm flex items-center justify-center text-slate-400 font-black text-2xl group-hover:bg-slate-900 group-hover:text-white transition-all shadow-inner italic">
                            {{ substr($batch->breeding_type, 0, 1) }}
                        </div>
                        <div class="ml-8 flex-1">
                            <p class="font-black text-slate-900 uppercase text-lg tracking-tighter italic leading-none mb-2">{{ $batch->code }}</p>
                            <div class="flex flex-wrap items-center gap-4">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest italic flex items-center">
                                    <i class="fas fa-location-dot mr-2 text-blue-500"></i> {{ $batch->building->name }}
                                </span>
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest italic flex items-center border-l border-slate-200 pl-4">
                                    <i class="fas fa-user-gear mr-2 text-slate-400"></i> {{ $batch->employee->last_name ?? 'STAFF' }}
                                </span>
                            </div>
                        </div>
                        <div class="text-right ml-4">
                            <p class="font-black text-slate-900 text-2xl tracking-tighter italic leading-none mb-1">{{ number_format($batch->current_quantity) }}</p>
                            <span class="px-3 py-1 bg-emerald-50 text-emerald-600 text-[8px] font-black uppercase tracking-widest rounded-lg border border-emerald-100 italic">{{ __("Cycle Actif") }}</span>
                        </div>
                    </a>
                @empty
                    <div class="text-center py-20 bg-slate-50/50 rounded-[3rem] border-4 border-dashed border-slate-100">
                        <i class="fas fa-folder-open text-5xl text-slate-200 mb-6 block"></i>
                        <p class="text-slate-400 font-black uppercase text-[11px] tracking-[0.4em] italic leading-none">{{ __("Aucun flux de production actif") }}</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- SIDEBAR --}}
        <div class="space-y-8">
            {{-- ALERTES SANITAIRES --}}
            <div class="bg-rose-600 p-10 rounded-[3.5rem] shadow-2xl shadow-rose-200 relative overflow-hidden group transition-all duration-500">
                <div class="absolute -right-12 -top-12 w-40 h-40 bg-white/10 rounded-full group-hover:scale-110 transition-transform duration-700"></div>
                <h4 class="text-white font-black uppercase text-[11px] tracking-[0.3em] mb-8 flex items-center italic">
                    <i class="fas fa-biohazard mr-4 animate-pulse text-xl"></i> {{ __("Sécurité Biosanitaire") }}
                </h4>
                <div class="p-6 bg-white/10 backdrop-blur-md rounded-[2rem] border border-white/20 text-white shadow-inner">
                    <p class="text-[11px] font-black leading-relaxed italic uppercase tracking-wider">
                        {{ __("Vigilance stable : Aucune mortalité anormale signalée sur les dernières 24h.") }}
                    </p>
                </div>
            </div>

            {{-- PLANNING VACCINAL --}}
            <div class="bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-sm relative overflow-hidden group">
                <div class="flex items-center justify-between mb-10">
                    <h4 class="text-slate-900 font-black uppercase text-[11px] tracking-[0.2em] italic leading-none">{{ __("Agenda Prophylaxie") }}</h4>
                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                </div>
                
                <div class="space-y-6">
                    <div class="flex items-start group/item">
                        <div class="w-12 h-12 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-300 mr-5 group-hover/item:bg-blue-600 group-hover/item:text-white transition-all duration-300 shadow-inner">
                            <i class="fas fa-vial text-sm"></i>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase italic tracking-widest leading-none mb-2">{{ __("Prochaine Échéance") }}</p>
                            <p class="text-xs font-black text-slate-500 uppercase italic leading-tight">{{ __("Zéro intervention programmée pour demain") }}</p>
                        </div>
                    </div>
                </div>
                
                <button class="w-full mt-10 py-5 bg-slate-50 border-2 border-dashed border-slate-200 rounded-[2rem] text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] hover:bg-slate-900 hover:text-white hover:border-slate-900 transition-all duration-500 italic border-none cursor-pointer">
                    <i class="fas fa-calendar-plus mr-2"></i> {{ __("Planifier un Rappel Master") }}
                </button>
            </div>
        </div>
    </div>
</div>
@endsection