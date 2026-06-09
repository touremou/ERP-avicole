<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-5">
                <a href="{{ route('protocols.index') }}" class="group flex items-center justify-center w-12 h-12 bg-white border border-slate-200 text-slate-400 hover:text-slate-800 rounded-2xl transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-circle-chevron-left text-xl group-hover:-translate-x-1 transition-transform"></i>
                </a>
                <div>
                    <h2 class="font-black text-xl text-slate-800 uppercase italic tracking-tighter leading-none">
                        💉 Configuration : {{ $protocol->name }}
                    </h2>
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-2 italic">Architecture chronologique du standard</p>
                </div>
            </div>
            <span class="px-5 py-2 bg-slate-900 text-yellow-500 rounded-xl text-[9px] font-black uppercase italic tracking-[0.2em] shadow-xl ring-1 ring-white/10">
                Mode Édition Séquence
            </span>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-3 gap-10">
            
            {{-- FORMULAIRE D'AJOUT D'ÉTAPE (C) --}}
            <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 h-fit sticky top-8 text-left">
                <h3 class="text-[10px] font-black uppercase text-blue-600 mb-8 tracking-[0.2em] italic leading-none border-b border-slate-50 pb-4">Ajouter une intervention</h3>
                
                <form action="{{ route('protocols.addStep', $protocol->id) }}" method="POST" class="space-y-6">
                    @csrf
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2 tracking-widest leading-none">Jour cible (Chronologie)</label>
                        <div class="relative">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-300 text-2xl font-black italic">J</span>
                            <input type="number" name="day_number" class="w-full pl-12 p-5 bg-slate-50 border-none rounded-2xl shadow-inner text-2xl font-black text-blue-600 italic outline-none focus:ring-4 focus:ring-blue-500/10 transition-all" placeholder="7" required>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2 tracking-widest leading-none">Nom de l'acte / Produit</label>
                        <input type="text" name="action_name" placeholder="EX: VACCIN GUMBORO" class="w-full p-5 bg-slate-50 border-none rounded-2xl shadow-inner uppercase font-black text-slate-700 italic outline-none focus:ring-4 focus:ring-blue-500/10 transition-all" required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2 tracking-widest leading-none">Type</label>
                            <select name="type" class="w-full p-5 bg-slate-50 border-none rounded-2xl shadow-inner text-[10px] font-black uppercase italic appearance-none cursor-pointer outline-none focus:ring-4 focus:ring-blue-500/10">
                                <option value="Vaccin">💉 Vaccin</option>
                                <option value="Traitement">💊 Traitement</option>
                                <option value="Vitamine">✨ Vitamine</option>
                                <option value="Désinfection">🧼 Hygiène</option>
                            </select>
                        </div>
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-2 tracking-widest leading-none">Méthode</label>
                            <input type="text" name="method" placeholder="Eau, Spray..." class="w-full p-5 bg-slate-50 border-none rounded-2xl shadow-inner text-[10px] uppercase font-black italic outline-none focus:ring-4 focus:ring-blue-500/10">
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white p-6 rounded-[2rem] uppercase font-black tracking-[0.3em] text-[10px] hover:bg-blue-600 transition-all shadow-2xl group border-none cursor-pointer italic mt-4">
                        <i class="fa-solid fa-plus-circle mr-2 group-hover:rotate-90 transition-transform"></i>
                        Ajouter au modèle
                    </button>
                </form>
            </div>

            {{-- LISTE DES ÉTAPES DU MODÈLE (L/S) --}}
            <div class="lg:col-span-2 space-y-6 text-left">
                <h3 class="text-[10px] font-black uppercase text-slate-400 mb-6 tracking-[0.2em] ml-6 italic">Séquence chronologique du protocole AviSmart</h3>
                
                @forelse($protocol->steps->sortBy('day_number') as $step)
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 flex items-center justify-between group hover:border-blue-500 hover:shadow-xl hover:shadow-blue-500/5 transition-all duration-300 relative overflow-hidden">
                        
                        {{-- Indicateur visuel de type --}}
                        <div @class([
                            'absolute top-0 bottom-0 left-0 w-2',
                            'bg-purple-500' => $step->type === 'Vaccin',
                            'bg-blue-500' => $step->type === 'Traitement',
                            'bg-yellow-500' => $step->type === 'Vitamine',
                            'bg-slate-400' => $step->type === 'Désinfection',
                        ])></div>

                        <div class="flex items-center gap-8 ml-4">
                            <div class="w-16 h-16 bg-slate-900 text-white rounded-[1.5rem] flex flex-col items-center justify-center leading-none shadow-xl transform group-hover:rotate-3 transition-transform">
                                <span class="text-[8px] font-black uppercase opacity-50 mb-1 italic">Jour</span>
                                <span class="text-2xl font-black italic tracking-tighter">{{ $step->day_number }}</span>
                            </div>
                            <div>
                                <h4 class="font-black text-slate-800 uppercase tracking-tighter text-lg leading-none mb-2 italic">{{ $step->action_name }}</h4>
                                <div class="flex gap-3">
                                    <span class="text-[8px] px-3 py-1 bg-slate-100 rounded-lg text-slate-500 uppercase font-black tracking-widest italic border border-slate-200">{{ $step->type }}</span>
                                    <span class="text-[8px] px-3 py-1 bg-blue-50 rounded-lg text-blue-600 uppercase font-black tracking-widest italic border border-blue-100">{{ $step->method ?? 'Indéfini' }}</span>
                                </div>
                            </div>
                        </div>
                        
                        {{-- Permission S : Suppression --}}
                        <form action="{{ route('protocols.destroyStep', $step->id) }}" method="POST" onsubmit="return confirm('DÉCISION CRITIQUE : Supprimer définitivement cette étape du protocole master ?')">
                            @csrf @method('DELETE')
                            <button class="opacity-0 group-hover:opacity-100 p-4 text-slate-300 hover:text-rose-600 transition-all border-none bg-transparent cursor-pointer transform translate-x-4 group-hover:translate-x-0 duration-300">
                                <i class="fa-solid fa-trash-can text-lg"></i>
                            </button>
                        </form>
                    </div>
                @empty
                    <div class="p-24 border-4 border-dashed border-slate-100 rounded-[4rem] text-center bg-white/50 italic flex flex-col items-center justify-center">
                        <i class="fa-solid fa-vial-circle-check text-5xl text-slate-200 mb-6"></i>
                        <p class="text-slate-400 uppercase text-[11px] font-black tracking-[0.3em] leading-loose">
                            Séquence vierge.<br>Commence par indexer l'étape J1.
                        </p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>