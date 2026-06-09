<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-slate-900 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-chart-pie text-lg"></i></div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Centre de Rapports</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">Analyse technique & financière</p>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-600 rounded-full text-[8px] font-black uppercase italic">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> Live
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 text-left">

                {{-- PERFORMANCE TECHNIQUE --}}
                @can('elevage.L')
                <a href="{{ route('reports.technical') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-blue-600 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-microscope text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">Performance Technique</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">FCR, croissance, mortalité par lot actif</p>
                    <div class="flex items-center gap-2 text-blue-600 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        KPIs <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-chart-line absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-blue-50 transition-colors"></i>
                </a>
                @endcan

                {{-- SANTÉ & PHARMACIE --}}
                @can('elevage.L')
                <a href="{{ route('reports.health_finance') }}" class="group bg-slate-900 p-8 rounded-2xl shadow-lg hover:bg-slate-800 transition-all no-underline relative overflow-hidden border-b-4 border-emerald-500">
                    <div class="w-12 h-12 bg-emerald-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-file-invoice-dollar text-lg"></i></div>
                    <h3 class="text-base font-black text-white uppercase tracking-tighter mb-2 italic">Santé & Pharmacie</h3>
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-6">Coût prophylaxie par lot, {{ setting('general.currency', 'GNF') }}/tête</p>
                    <div class="flex items-center gap-2 text-emerald-400 text-[9px] font-black uppercase tracking-widest border-t border-white/10 pt-4">
                        Bilan <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-syringe absolute -right-4 -bottom-4 text-7xl text-white/5"></i>
                </a>
                @endcan

                {{-- FLUX MENSUEL --}}
                @can('admin.L')
                <a href="{{ route('reports.monthly') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-orange-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-check text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">Flux de Trésorerie</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">Charges aliment + santé, {{ setting('general.currency', 'GNF') }} mensuel</p>
                    <div class="flex items-center gap-2 text-orange-500 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        Analyse <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-coins absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-orange-50 transition-colors"></i>
                </a>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
