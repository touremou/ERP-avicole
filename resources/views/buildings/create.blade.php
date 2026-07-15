<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🏢 Nouveau Bâtiment / Unité')" :subtitle="__('Extension du parc de production')" icon="fa-warehouse" accent="indigo" :back="route('buildings.index')" />
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-3 gap-8 text-left">
            
            {{-- FORMULAIRE DE CRÉATION --}}
            <div class="lg:col-span-2">
                @if ($errors->any())
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-2xl shadow-sm">
                        <ul class="text-[10px] font-black text-red-600 uppercase tracking-tight list-disc ml-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('buildings.store') }}" method="POST" class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 space-y-8">
                    @csrf

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 tracking-widest">{{ __("Désignation") }}</label>
                            <input type="text" name="name" value="{{ old('name') }}" placeholder="{{ __("ex: Hangar A1") }}" required
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 tracking-widest leading-none">{{ __("Vocation Technique") }}</label>
                            <select name="type" required class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-blue-600 shadow-inner appearance-none focus:ring-2 focus:ring-blue-500 italic cursor-pointer outline-none">
                                <option value="mixte" {{ old('type') == 'mixte' ? 'selected' : '' }}>{{ __("🔄 Mixte (TOUT TYPE)") }}</option>
                                <option value="chair" {{ old('type') == 'chair' ? 'selected' : '' }}>{{ __("🍗 Poulet de chair") }}</option>
                                <option value="ponte" {{ old('type') == 'ponte' ? 'selected' : '' }}>{{ __("🥚 Pondeuses") }}</option>
                                <option value="poussiniere" {{ old('type') == 'poussiniere' ? 'selected' : '' }}>{{ __("🐣 Poussinière") }}</option>
                                <option value="reproducteur" {{ old('type') == 'reproducteur' ? 'selected' : '' }}>{{ __("🧬 Reproducteurs") }}</option>
                                <option value="bergerie" {{ old('type') == 'bergerie' ? 'selected' : '' }}>{{ __("🐑 Bergerie (Ovins)") }}</option>
                                <option value="chevrerie" {{ old('type') == 'chevrerie' ? 'selected' : '' }}>{{ __("🐐 Chèvrerie (Caprins)") }}</option>
                                <option value="etable" {{ old('type') == 'etable' ? 'selected' : '' }}>{{ __("🐄 Étable (Bovins)") }}</option>
                                <option value="bassin" {{ old('type') == 'bassin' ? 'selected' : '' }}>{{ __("🐟 Bassin (Pisciculture)") }}</option>
                                <option value="lapiniere" {{ old('type') == 'lapiniere' ? 'selected' : '' }}>{{ __("🐇 Lapinière") }}</option>
                                <option value="porcherie" {{ old('type') == 'porcherie' ? 'selected' : '' }}>{{ __("🐷 Porcherie") }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 tracking-widest">{{ __("Surface Totale (m²)") }}</label>
                            <div class="relative">
                                <input type="number" name="surface" value="{{ old('surface') }}" step="0.01" min="1" required
                                       class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner focus:ring-2 focus:ring-emerald-500 outline-none pr-12">
                                <span class="absolute right-4 top-4 text-slate-300 text-[10px] font-black uppercase">m²</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 tracking-widest">{{ __("Capacité Max (Sujets)") }}</label>
                            <div class="relative">
                                <input type="number" min="1" name="capacity" value="{{ old('capacity') }}" required
                                       class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner focus:ring-2 focus:ring-emerald-500 outline-none pr-16">
                                <span class="absolute right-4 top-4 text-slate-300 text-[10px] font-black uppercase italic">{{ __("Têtes") }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row gap-4 pt-6">
                        <a href="{{ route('buildings.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-6 rounded-[2rem] shadow-sm hover:bg-slate-50 transition-all text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-2 no-underline">
                            <i class="fas fa-times"></i> {{ __("Annuler") }}
                        </a>

                        {{-- PERMISSION C : CRÉATION --}}
                        @can('elevage.C')
                        <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-6 rounded-[2rem] hover:bg-blue-600 transition-all uppercase tracking-[0.2em] text-[10px] italic shadow-xl group cursor-pointer border-none">
                            <i class="fas fa-check mr-2 group-hover:scale-110 transition-transform"></i>
                            {{ __("Enregistrer le nouveau bâtiment") }}
                        </button>
                        @else
                        <div class="flex-[2] bg-slate-100 text-slate-400 font-black py-6 rounded-[2rem] text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-2 cursor-not-allowed">
                            <i class="fas fa-lock"></i> {{ __("Droits de création requis") }}
                        </div>
                        @endcan
                    </div>
                </form>
            </div>

            {{-- LISTE LATÉRALE --}}
            <div class="bg-slate-50 p-8 rounded-[3rem] border border-slate-100 h-fit">
                <h3 class="text-[10px] font-black text-slate-400 uppercase mb-6 tracking-widest flex items-center">
                    <i class="fas fa-list-ul mr-2 text-blue-500"></i> {{ __("Unités déjà en parc") }} ({{ $buildings->count() }})
                </h3>
                <div class="space-y-3">
                    @forelse($buildings as $b)
                        <div class="flex items-center justify-between p-4 bg-white rounded-2xl shadow-sm border border-slate-100 hover:border-blue-200 transition-colors">
                            <div class="text-left">
                                <p class="text-[11px] font-black text-slate-800 uppercase tracking-tighter leading-none">{{ $b->name }}</p>
                                <span class="text-[7px] text-blue-500 uppercase font-black italic tracking-widest">{{ $b->type ?? 'N/A' }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-[9px] font-black text-slate-400 italic">{{ number_format($b->capacity ?? 0) }}</span>
                                <p class="text-[6px] text-slate-300 uppercase leading-none">{{ __("Capacité") }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="py-10 text-center opacity-30">
                            <i class="fas fa-warehouse text-2xl mb-2"></i>
                            <p class="text-[9px] uppercase font-black tracking-widest">{{ __("Parc vide") }}</p>
                        </div>
                    @endforelse
                </div>
                
                <div class="mt-8 p-4 bg-blue-600/5 rounded-2xl border border-blue-600/10">
                    <p class="text-[8px] font-black text-blue-600 uppercase italic leading-normal">
                        {{ __("Rigueur : Toute nouvelle unité doit être validée par la direction technique avant mise en service.") }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>