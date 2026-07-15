<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Registres HACCP')" :subtitle="__('Conformité opposable — CCP, températures, nettoyage, sous-produits')" icon="fa-clipboard-check" accent="rose" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            <x-flash />

            <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest m-0">
                <i class="fa-solid fa-circle-info mr-1"></i>{{ __("Registres immuables (insert-only) — horodatage relevé + synchronisation conservés. Compteurs sur :days jours.", ['days' => $counters['days']]) }}
            </p>

            {{-- CARTES DES REGISTRES --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

                {{-- CCP --}}
                <a href="{{ route('slaughter.registres.ccp') }}" class="group bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm hover:border-rose-200 hover:shadow-lg transition-all no-underline block">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-rose-600 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3 group-hover:rotate-0 transition-transform"><i class="fa-solid fa-shield-halved text-lg"></i></div>
                        @if($counters['ccp_nc'] > 0)
                            <span class="text-[8px] font-black text-red-700 bg-red-100 px-2 py-1 rounded-full uppercase tracking-widest">{{ __(":n non conf.", ['n' => $counters['ccp_nc']]) }}</span>
                        @endif
                    </div>
                    <p class="text-sm font-black text-slate-800 uppercase tracking-tight m-0">{{ __("Registre CCP") }}</p>
                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest m-0 mt-1">{{ __("Points critiques 1-4") }}</p>
                    <p class="text-[10px] text-slate-500 font-black m-0 mt-3">{{ __(":n relevés", ['n' => $counters['ccp']]) }}</p>
                </a>

                {{-- TEMPÉRATURES --}}
                <a href="{{ route('slaughter.registres.temperatures') }}" class="group bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm hover:border-rose-200 hover:shadow-lg transition-all no-underline block">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-rose-600 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3 group-hover:rotate-0 transition-transform"><i class="fa-solid fa-temperature-half text-lg"></i></div>
                        <span class="text-[8px] font-black px-2 py-1 rounded-full uppercase tracking-widest {{ $counters['temp_today'] >= $counters['temp_req'] ? 'text-emerald-700 bg-emerald-100' : 'text-amber-700 bg-amber-100' }}">{{ __("auj. :n/:r", ['n' => $counters['temp_today'], 'r' => $counters['temp_req']]) }}</span>
                    </div>
                    <p class="text-sm font-black text-slate-800 uppercase tracking-tight m-0">{{ __("Températures") }}</p>
                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest m-0 mt-1">{{ __("Chaîne du froid (E4)") }}</p>
                    <p class="text-[10px] text-slate-500 font-black m-0 mt-3">{{ __(":n relevés", ['n' => $counters['temp']]) }}</p>
                </a>

                {{-- NETTOYAGE --}}
                <a href="{{ route('slaughter.registres.nettoyage') }}" class="group bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm hover:border-rose-200 hover:shadow-lg transition-all no-underline block">
                    <div class="w-12 h-12 bg-rose-600 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3 group-hover:rotate-0 transition-transform mb-4"><i class="fa-solid fa-broom text-lg"></i></div>
                    <p class="text-sm font-black text-slate-800 uppercase tracking-tight m-0">{{ __("Nettoyage") }}</p>
                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest m-0 mt-1">{{ __("Désinfection (E7)") }}</p>
                    <p class="text-[10px] text-slate-500 font-black m-0 mt-3">{{ __(":n opérations", ['n' => $counters['cleaning']]) }}</p>
                </a>

                {{-- SOUS-PRODUITS --}}
                <a href="{{ route('slaughter.registres.sous_produits') }}" class="group bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm hover:border-rose-200 hover:shadow-lg transition-all no-underline block">
                    <div class="w-12 h-12 bg-rose-600 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3 group-hover:rotate-0 transition-transform mb-4"><i class="fa-solid fa-recycle text-lg"></i></div>
                    <p class="text-sm font-black text-slate-800 uppercase tracking-tight m-0">{{ __("Sous-produits") }}</p>
                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest m-0 mt-1">{{ __("Sang, plumes, viscères (E9)") }}</p>
                    <p class="text-[10px] text-slate-500 font-black m-0 mt-3">{{ __(":n collectes", ['n' => $counters['byproducts']]) }}</p>
                </a>
            </div>

            {{-- EXPORTS INSPECTION VÉTÉRINAIRE --}}
            <div class="bg-slate-900 text-white p-6 rounded-[2.5rem] not-italic">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-rose-500/20 rounded-2xl flex items-center justify-center text-rose-400"><i class="fa-solid fa-file-pdf"></i></div>
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-widest m-0">{{ __("Exports pour l'inspection vétérinaire") }}</p>
                        <p class="text-[9px] text-slate-400 font-bold m-0">{{ __("Registre opposable des 30 derniers jours (PDF, horodatages complets)") }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('slaughter.registres.export', ['type' => 'ccp']) }}" class="px-5 py-2.5 bg-white text-slate-900 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-rose-100 transition-all no-underline"><i class="fa-solid fa-shield-halved mr-1"></i> {{ __("CCP") }}</a>
                    <a href="{{ route('slaughter.registres.export', ['type' => 'temperatures']) }}" class="px-5 py-2.5 bg-white text-slate-900 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-rose-100 transition-all no-underline"><i class="fa-solid fa-temperature-half mr-1"></i> {{ __("Températures") }}</a>
                    <a href="{{ route('slaughter.registres.export', ['type' => 'nettoyage']) }}" class="px-5 py-2.5 bg-white text-slate-900 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-rose-100 transition-all no-underline"><i class="fa-solid fa-broom mr-1"></i> {{ __("Nettoyage") }}</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
