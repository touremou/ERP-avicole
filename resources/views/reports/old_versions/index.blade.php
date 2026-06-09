<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div class="text-left">
                <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🚀 Centre d'Analyse & Rapports
                </h2>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-2 italic leading-none">
                    Pilotez votre rentabilité et vos performances techniques
                </p>
            </div>
            {{-- Indicateur de temps réel --}}
            <div class="flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-600 rounded-full text-[9px] font-black uppercase italic animate-pulse">
                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span> Données synchronisées
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-10 text-left">
                
                {{-- CARTE 01 : PERFORMANCE TECHNIQUE (Permission L) --}}
                <a href="{{ route('reports.technical') }}" class="group relative bg-white p-10 rounded-[4rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 overflow-hidden no-underline italic">
                    <div class="absolute right-0 top-0 p-10 opacity-5 group-hover:scale-125 group-hover:rotate-6 transition-all duration-700 text-blue-600">
                        <i class="fa-solid fa-chart-area text-[120px]"></i>
                    </div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-blue-600 text-white rounded-[1.5rem] flex items-center justify-center mb-8 shadow-xl shadow-blue-200">
                            <i class="fa-solid fa-microscope text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-black text-slate-800 uppercase tracking-tighter mb-3 italic leading-none">Performance Technique</h3>
                        <p class="text-[11px] text-slate-400 uppercase tracking-widest leading-relaxed mb-10 font-black">Indice de consommation (FCR), croissance moyenne et courbes de mortalité.</p>
                        
                        <div class="flex items-center gap-3 text-blue-600 text-[10px] font-black uppercase tracking-[0.2em] border-t border-slate-50 pt-6">
                            Explorer les KPIs <i class="fa-solid fa-chevron-right group-hover:translate-x-2 transition-transform"></i>
                        </div>
                    </div>
                </a>

                {{-- CARTE 02 : SANTÉ & FINANCE (Permission L) --}}
                <a href="{{ route('reports.health_finance') }}" class="group relative bg-slate-900 p-10 rounded-[4rem] shadow-2xl hover:bg-slate-800 transition-all duration-500 overflow-hidden no-underline italic border-b-8 border-emerald-500">
                    <div class="absolute right-0 top-0 p-10 opacity-10 group-hover:rotate-12 transition-all duration-700 text-emerald-400">
                        <i class="fa-solid fa-hand-holding-dollar text-[120px]"></i>
                    </div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-emerald-500 text-slate-900 rounded-[1.5rem] flex items-center justify-center mb-8 shadow-xl shadow-emerald-500/20">
                            <i class="fa-solid fa-file-invoice-dollar text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-black text-white uppercase tracking-tighter mb-3 italic leading-none">Santé & Pharmacie</h3>
                        <p class="text-[11px] text-slate-500 uppercase tracking-widest leading-relaxed mb-10 font-black">Audit financier de la prophylaxie et rentabilité brute par lot traité.</p>
                        
                        <div class="flex items-center gap-3 text-emerald-400 text-[10px] font-black uppercase tracking-[0.2em] border-t border-white/5 pt-6">
                            Bilan Vétérinaire <i class="fa-solid fa-chevron-right group-hover:translate-x-2 transition-transform"></i>
                        </div>
                    </div>
                </a>

                {{-- CARTE 03 : FLUX MENSUEL (Permission L) --}}
                <a href="{{ route('reports.monthly') }}" class="group relative bg-white p-10 rounded-[4rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 overflow-hidden no-underline italic">
                    <div class="absolute right-0 top-0 p-10 opacity-5 group-hover:scale-90 transition-all duration-700 text-orange-500">
                        <i class="fa-solid fa-calendar-check text-[120px]"></i>
                    </div>
                    <div class="relative z-10">
                        <div class="w-16 h-16 bg-orange-500 text-white rounded-[1.5rem] flex items-center justify-center mb-8 shadow-xl shadow-orange-200">
                            <i class="fa-solid fa-wheat-awn text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-black text-slate-800 uppercase tracking-tighter mb-3 italic leading-none">Flux de Trésorerie</h3>
                        <p class="text-[11px] text-slate-400 uppercase tracking-widest leading-relaxed mb-10 font-black">Synthèse des achats d'aliments, charges fixes et équilibre mensuel.</p>
                        
                        <div class="flex items-center gap-3 text-orange-500 text-[10px] font-black uppercase tracking-[0.2em] border-t border-slate-50 pt-6">
                            Analyse Mensuelle <i class="fa-solid fa-chevron-right group-hover:translate-x-2 transition-transform"></i>
                        </div>
                    </div>
                </a>

            </div>

            {{-- FOOTER INFO --}}
            <div class="mt-16 text-center">
                <p class="text-[9px] font-black text-slate-300 uppercase tracking-[0.5em] italic">AviSmart Intelligence Engine • v3.0</p>
            </div>

        </div>
    </div>
</x-app-layout>