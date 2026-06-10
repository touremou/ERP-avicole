<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('buildings.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                    <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform"></i>
                    <span class="text-[10px] font-black uppercase italic tracking-widest">Retour au parc</span>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        {{ __('Configuration :') }} {{ $building->name }}
                    </h2>
                    <p class="text-[9px] font-bold text-orange-500 uppercase mt-1 tracking-[0.2em] italic">Maintenance technique</p>
                </div>
            </div>
            <div class="hidden md:block">
                <span class="px-4 py-2 bg-orange-50 rounded-xl text-[10px] font-black uppercase text-orange-600 italic tracking-widest border border-orange-100">B-{{ str_pad($building->id, 3, '0', STR_PAD_LEFT) }}</span>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="bg-red-50 border-l-4 border-red-500 p-6 rounded-r-[2rem] mb-8 shadow-sm animate-pulse">
                    <ul class="text-[10px] font-black text-red-600 uppercase tracking-tight list-disc ml-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('buildings.update', $building->id) }}" method="POST" class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 space-y-8 relative overflow-hidden text-left">
                @csrf
                @method('PUT')

                {{-- La variable $isOccupied est fournie par le Controller --}}
                
                {{-- NOM DU BÂTIMENT --}}
                <div class="relative z-10">
                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-1 tracking-widest italic">Désignation Officielle</label>
                    <input type="text" name="name" value="{{ old('name', $building->name) }}" required 
                           class="w-full px-6 py-4 bg-slate-50 rounded-2xl font-black text-slate-700 outline-none border-2 border-transparent focus:border-orange-500 transition shadow-inner italic">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 relative z-10">
                    {{-- TYPE DE PRODUCTION (VERROUILLÉ SI OCCUPÉ) --}}
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-1 tracking-widest italic leading-none">
                            Vocation / Type 
                            @if($isOccupied) <i class="fas fa-lock ml-1 text-orange-500"></i> @endif
                        </label>
                        
                        @if($isOccupied)
                            <input type="hidden" name="type" value="{{ $building->type }}">
                            <div class="w-full px-6 py-4 rounded-2xl bg-orange-50 border border-orange-100 font-black text-orange-600 text-[10px] uppercase italic flex items-center gap-2">
                                <i class="fas fa-info-circle"></i> Verrouillé : Bande active en cours
                            </div>
                        @else
                            <select name="type" class="w-full px-6 py-4 rounded-2xl bg-slate-50 border-2 border-transparent focus:border-orange-500 outline-none font-black text-slate-700 appearance-none shadow-inner uppercase text-[10px] italic cursor-pointer">
                                <option value="mixte" {{ old('type', $building->type) == 'mixte' ? 'selected' : '' }}>🔄 Mixte (TOUT TYPE)</option>
                                <option value="poussiniere" {{ old('type', $building->type) == 'poussiniere' ? 'selected' : '' }}>🐣 Poussinière</option>
                                <option value="chair" {{ old('type', $building->type) == 'chair' ? 'selected' : '' }}>🍗 Poulet de chair</option>
                                <option value="ponte" {{ old('type', $building->type) == 'ponte' ? 'selected' : '' }}>🥚 Pondeuses</option>
                                <option value="reproducteur" {{ old('type', $building->type) == 'reproducteur' ? 'selected' : '' }}>🧬 Reproducteurs</option>
                                <option value="bergerie" {{ old('type', $building->type) == 'bergerie' ? 'selected' : '' }}>🐑 Bergerie (Ovins)</option>
                                <option value="chevrerie" {{ old('type', $building->type) == 'chevrerie' ? 'selected' : '' }}>🐐 Chèvrerie (Caprins)</option>
                                <option value="bassin" {{ old('type', $building->type) == 'bassin' ? 'selected' : '' }}>🐟 Bassin (Pisciculture)</option>
                                <option value="lapiniere" {{ old('type', $building->type) == 'lapiniere' ? 'selected' : '' }}>🐇 Lapinière</option>
                                <option value="porcherie" {{ old('type', $building->type) == 'porcherie' ? 'selected' : '' }}>🐷 Porcherie</option>
                            </select>
                        @endif
                    </div>

                    {{-- ÉTAT SANITAIRE --}}
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-1 tracking-widest italic leading-none">
                            Statut Opérationnel
                            @if($isOccupied) <i class="fas fa-lock ml-1 text-orange-500"></i> @endif
                        </label>
                        <select name="status" class="w-full px-6 py-4 rounded-2xl bg-slate-50 border-2 border-transparent focus:border-orange-500 outline-none font-black text-slate-700 appearance-none shadow-inner uppercase text-[10px] italic cursor-pointer">
                            {{-- Si occupé, on désactive l'option Vide pour coller à la règle métier --}}
                            <option value="Vide" {{ old('status', $building->status) == 'Vide' ? 'selected' : '' }} {{ $isOccupied ? 'disabled' : '' }}>🟢 Vide / Désinfecté</option>
                            <option value="Occupé" {{ old('status', $building->status) == 'Occupé' ? 'selected' : '' }}>🔴 Occupé</option>
                            <option value="En désinfection" {{ old('status', $building->status) == 'En désinfection' ? 'selected' : '' }}>🟠 En désinfection</option>
                        </select>
                    </div>

                    {{-- SURFACE --}}
                    <div class="bg-blue-50 p-6 rounded-[2.5rem] border border-blue-100 shadow-sm">
                        <label class="block text-[10px] font-black text-blue-400 uppercase mb-2 ml-1 italic">Surface (m²)</label>
                        <div class="flex items-end gap-2">
                            <input type="number" min="0" name="surface" value="{{ old('surface', $building->surface) }}" step="0.1" required 
                                   class="w-full bg-transparent text-4xl font-black text-blue-700 outline-none border-none p-0 focus:ring-0">
                            <span class="text-blue-300 font-black text-xs mb-1 uppercase">m²</span>
                        </div>
                    </div>

                    {{-- CAPACITÉ --}}
                    <div class="bg-yellow-50 p-6 rounded-[2.5rem] border border-yellow-100 shadow-sm">
                        <label class="block text-[10px] font-black text-yellow-600 uppercase mb-2 ml-1 italic">Capacité (Sujets)</label>
                        <div class="flex items-end gap-2">
                            <input type="number" min="0" name="capacity" value="{{ old('capacity', $building->capacity) }}" required 
                                   class="w-full bg-transparent text-4xl font-black text-yellow-700 outline-none border-none p-0 focus:ring-0">
                            <span class="text-yellow-400 font-black text-xs mb-1 italic uppercase">Têtes</span>
                        </div>
                    </div>
                </div>

                {{-- ACTIONS --}}
                <div class="flex flex-col md:flex-row gap-4 pt-6">
                    <a href="{{ route('buildings.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-6 rounded-[2rem] shadow-sm hover:bg-slate-50 transition-all text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-2 no-underline">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    
                    @can('elevage.M')
                    <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-6 rounded-[2rem] hover:bg-orange-600 transition-all uppercase tracking-[0.2em] text-[10px] italic shadow-xl group cursor-pointer border-none">
                        <i class="fas fa-sync-alt mr-2 group-hover:rotate-180 transition-transform duration-700"></i>
                        Enregistrer les modifications
                    </button>
                    @else
                    <div class="flex-[2] bg-slate-100 text-slate-400 font-black py-6 rounded-[2rem] text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-2 cursor-not-allowed">
                        <i class="fas fa-lock"></i> Modification restreinte
                    </div>
                    @endcan
                </div>
            </form>
        </div>
    </div>
</x-app-layout>