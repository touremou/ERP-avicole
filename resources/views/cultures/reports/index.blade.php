<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-700 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-chart-bar text-lg"></i>
                </div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Rapports Production Végétale") }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ __("Rendements · Intrants · Campagnes · Transformations") }}</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-left">

                {{-- RENDEMENTS --}}
                @can('cultures.L')
                <a href="{{ route('crop-reports.yield') }}" class="group bg-slate-900 p-8 rounded-[2rem] shadow-lg hover:bg-slate-800 transition-all no-underline relative overflow-hidden border-b-4 border-green-500">
                    <div class="w-12 h-12 bg-green-600 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-wheat-awn text-lg"></i>
                    </div>
                    <h3 class="text-base font-black text-white uppercase tracking-tighter mb-2 italic">{{ __("Analyse des Rendements") }}</h3>
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-6">{{ __("Kg/ha par culture, par cycle et par saison") }}</p>
                    <div class="flex items-center gap-2 text-green-400 text-[9px] font-black uppercase tracking-widest border-t border-white/10 pt-4">
                        {{ __("Voir le rapport") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-seedling absolute -right-4 -bottom-4 text-7xl text-white/5"></i>
                </a>
                @endcan

                {{-- INTRANTS --}}
                @can('cultures.L')
                <a href="{{ route('crop-reports.inputs') }}" class="group bg-white p-8 rounded-[2rem] border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-amber-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-flask-vial text-lg"></i>
                    </div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Coûts des Intrants") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Engrais, semences, phyto — répartition par type et par culture") }}</p>
                    <div class="flex items-center gap-2 text-amber-600 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("Voir le rapport") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-droplet absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-amber-50 transition-colors"></i>
                </a>
                @endcan

                {{-- CAMPAGNES --}}
                @can('cultures.L')
                <a href="{{ route('crop-reports.campaigns') }}" class="group bg-white p-8 rounded-[2rem] border border-slate-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all no-underline relative overflow-hidden">
                    <div class="w-12 h-12 bg-sky-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-calendar-days text-lg"></i>
                    </div>
                    <h3 class="text-base font-black text-slate-800 uppercase tracking-tighter mb-2 italic">{{ __("Bilan des Campagnes") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black mb-6">{{ __("Objectif vs réalisé, marge nette par saison agricole") }}</p>
                    <div class="flex items-center gap-2 text-sky-600 text-[9px] font-black uppercase tracking-widest border-t border-slate-50 pt-4">
                        {{ __("Voir le rapport") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-cloud-sun absolute -right-4 -bottom-4 text-7xl text-slate-50 group-hover:text-sky-50 transition-colors"></i>
                </a>
                @endcan

                {{-- TRANSFORMATIONS --}}
                @can('cultures.L')
                <a href="{{ route('crop-reports.transformations') }}" class="group bg-slate-900 p-8 rounded-[2rem] shadow-lg hover:bg-slate-800 transition-all no-underline relative overflow-hidden border-b-4 border-emerald-500">
                    <div class="w-12 h-12 bg-emerald-500 text-white rounded-xl flex items-center justify-center mb-5 shadow-lg group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-industry text-lg"></i>
                    </div>
                    <h3 class="text-base font-black text-white uppercase tracking-tighter mb-2 italic">{{ __("Efficacité Transformation") }}</h3>
                    <p class="text-[9px] text-slate-500 uppercase tracking-widest font-black mb-6">{{ __("Rendement matière, valeur produit fini, coût de transformation") }}</p>
                    <div class="flex items-center gap-2 text-emerald-400 text-[9px] font-black uppercase tracking-widest border-t border-white/10 pt-4">
                        {{ __("Voir le rapport") }} <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform text-[8px]"></i>
                    </div>
                    <i class="fa-solid fa-gears absolute -right-4 -bottom-4 text-7xl text-white/5"></i>
                </a>
                @endcan

            </div>
        </div>
    </div>
</x-app-layout>
