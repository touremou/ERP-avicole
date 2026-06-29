<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Centre de Rapports')" :subtitle="__('Analyse technique & financière')" icon="fa-chart-pie" accent="slate">
            <x-slot name="actions">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-600 rounded-full text-[8px] font-black uppercase italic">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> {{ __("Live") }}
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 text-left">

                {{-- COMPTE DE RÉSULTAT (P&L) --}}
                @can('elevage.L')
                <a href="{{ route('reports.profit_loss') }}" class="group bg-slate-900 p-8 rounded-2xl shadow-lg hover:bg-slate-800 transition-all no-underline relative overflow-hidden border-b-4 border-amber-500">
                    <div class="w-12 h-12 bg-amber-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-scale-balanced text-lg"></i></div>
                    <h3 class="text-base font-black text-white uppercase tracking-tighter mb-2 italic">{{ __("Compte de Résultat") }}</h3>
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-6">{{ __("Produits, charges, résultat net — toutes activités") }}</p>
                    <div class="flex items-center gap-2 text-amber-400 text-[9px] font-black uppercase tracking-widest border-t border-white/10 pt-4">
                        {{ __("P&L consolidé") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-coins absolute -right-4 -bottom-4 text-7xl text-white/5"></i>
                </a>
                @endcan

                {{-- NURSERIE / REPRODUCTION --}}
                @can('elevage.L')
                <a href="{{ route('reports.nursery') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-pink-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-baby-carriage text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Nurserie / Reproduction") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Agnelage, chevrotage, sevrage — taux de sevrage") }}</p>
                    <div class="flex items-center gap-2 text-pink-600 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("Suivi") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-paw absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-pink-50 transition-colors"></i>
                </a>
                @endcan

                {{-- PERFORMANCE TECHNIQUE --}}
                @can('elevage.L')
                <a href="{{ route('reports.technical') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-blue-600 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-microscope text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Performance Technique") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("FCR, croissance, mortalité par lot actif") }}</p>
                    <div class="flex items-center gap-2 text-blue-600 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("KPIs") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-chart-line absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-blue-50 transition-colors"></i>
                </a>
                @endcan

                {{-- RAPPORT SANITAIRE (INCIDENTS) --}}
                @can('elevage.L')
                <a href="{{ route('reports.health_incidents') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-rose-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-shield-virus text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Rapport sanitaire") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Incidents par maladie, gravité, bâtiment, saison") }}</p>
                    <div class="flex items-center gap-2 text-rose-500 text-[9px] font-black uppercase tracking-widest border-t border-slate-100 pt-4">
                        {{ __("Analyser") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-virus-covid absolute -right-4 -bottom-4 text-7xl text-rose-50"></i>
                </a>
                @endcan

                {{-- SANTÉ & PHARMACIE --}}
                @can('elevage.L')
                <a href="{{ route('reports.health_finance') }}" class="group bg-slate-900 p-8 rounded-2xl shadow-lg hover:bg-slate-800 transition-all no-underline relative overflow-hidden border-b-4 border-emerald-500">
                    <div class="w-12 h-12 bg-emerald-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-file-invoice-dollar text-lg"></i></div>
                    <h3 class="text-base font-black text-white uppercase tracking-tighter mb-2 italic">{{ __("Santé & Pharmacie") }}</h3>
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-6">{{ __("Coût prophylaxie par lot,") }} {{ setting('general.currency', 'GNF') }}{{ __("/tête") }}</p>
                    <div class="flex items-center gap-2 text-emerald-400 text-[9px] font-black uppercase tracking-widest border-t border-white/10 pt-4">
                        {{ __("Bilan") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-syringe absolute -right-4 -bottom-4 text-7xl text-white/5"></i>
                </a>
                @endcan

                {{-- FLUX MENSUEL --}}
                @can('elevage.L')
                <a href="{{ route('reports.monthly') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-orange-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-check text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Flux de Trésorerie") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Charges aliment + santé,") }} {{ setting('general.currency', 'GNF') }} {{ __("mensuel") }}</p>
                    <div class="flex items-center gap-2 text-orange-500 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("Analyse") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-coins absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-orange-50 transition-colors"></i>
                </a>
                @endcan

                {{-- GMQ ENGRAISSEMENT --}}
                @can('elevage.L')
                <a href="{{ route('reports.gmq') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-emerald-700 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-chart-line text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("GMQ Engraissement") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Ruminants, porcins, lapins — gain moyen quotidien et sparkline pesées") }}</p>
                    <div class="flex items-center gap-2 text-emerald-700 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("Croissance") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-sheep absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-emerald-50 transition-colors"></i>
                </a>
                @endcan

                {{-- PISCICULTURE --}}
                @can('elevage.L')
                <a href="{{ route('reports.aquaculture') }}" class="group bg-white p-8 rounded-2xl border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-blue-700 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-water text-lg"></i></div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Pisciculture") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Qualité de l'eau, alertes et courbe de survie par bassin") }}</p>
                    <div class="flex items-center gap-2 text-blue-700 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("Bassins") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-fish absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-blue-50 transition-colors"></i>
                </a>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
