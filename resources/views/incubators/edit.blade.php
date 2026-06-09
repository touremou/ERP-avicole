<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center italic font-bold">
            <div class="text-left">
                <h2 class="text-2xl font-black uppercase text-slate-800 leading-none italic tracking-tighter">
                    Édition <span class="text-blue-600">{{ $incubator->name }}</span>
                </h2>
                <p class="text-[10px] text-slate-400 uppercase tracking-[0.3em] mt-2 font-black">Configuration technique de l'unité</p>
            </div>
            <a href="{{ route('incubators.index') }}" class="group px-5 py-3 bg-slate-900 text-white rounded-2xl font-black text-[10px] uppercase italic hover:bg-rose-600 transition-all shadow-xl no-underline tracking-widest">
                <i class="fa-solid fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i> Annuler
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            
            {{-- Permission M : Accès à la modification --}}
            @can('couvoir.M')
            <div class="bg-white rounded-[3.5rem] p-12 shadow-2xl border border-slate-100 relative overflow-hidden text-left">
                {{-- Indicateur visuel de modification --}}
                <div class="absolute top-0 right-0 p-8 opacity-5">
                    <i class="fa-solid fa-gears text-7xl"></i>
                </div>

                <form action="{{ route('incubators.update', $incubator->id) }}" method="POST" class="space-y-10 relative z-10">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                        {{-- Désignation --}}
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-3 block tracking-widest leading-none">Désignation de l'unité</label>
                            <input type="text" name="name" value="{{ old('name', $incubator->name) }}" required 
                                   class="w-full bg-slate-50 border-none rounded-[1.5rem] p-5 font-black italic shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10 transition-all text-slate-800 uppercase text-lg">
                        </div>

                        {{-- Capacité --}}
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase italic ml-3 block tracking-widest leading-none">Capacité maximale (Œufs)</label>
                            <input type="number" min="0" placeholder="0" name="capacity" value="{{ old('capacity', $incubator->capacity) }}" required 
                                   class="w-full bg-slate-50 border-none rounded-[1.5rem] p-5 font-black italic shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10 transition-all text-slate-800 text-lg">
                        </div>
                    </div>

                    {{-- Statut opérationnel --}}
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase italic ml-3 block tracking-widest leading-none">État opérationnel système</label>
                        
                        {{-- Le statut "Occupé" est grisé/caché car il est géré par l'Observer d'Incubation --}}
                        <select name="status" class="w-full bg-slate-50 border-none rounded-[1.5rem] p-6 font-black italic shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10 transition-all text-slate-800 appearance-none cursor-pointer">
                            <option value="Disponible" @selected($incubator->status == 'Disponible')>✅ OPÉRATIONNEL (DISPONIBLE)</option>
                            <option value="Maintenance" @selected($incubator->status == 'Maintenance')>🛠️ SAV / MAINTENANCE TECHNIQUE</option>
                            <option value="Panne" @selected($incubator->status == 'Panne')>🚨 HORS SERVICE (PANNE)</option>
                        </select>
                        <p class="text-[9px] text-orange-500 mt-3 ml-3 uppercase font-black tracking-tighter italic">
                            <i class="fa-solid fa-circle-info mr-1"></i> Mettre en maintenance ou panne verrouille l'affectation de nouveaux lots.
                        </p>
                    </div>

                    {{-- Bouton de validation --}}
                    <div class="pt-6">
                        <button type="submit" class="w-full bg-slate-900 text-white font-black py-7 rounded-[2rem] uppercase italic shadow-2xl shadow-slate-200 hover:bg-blue-600 hover:-translate-y-1 active:scale-95 transition-all tracking-[0.3em] text-[11px]">
                            Enregistrer la nouvelle configuration
                        </button>
                    </div>
                </form>
            </div>
            @else
            {{-- Accès Refusé --}}
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic font-black">
                <i class="fas fa-lock text-slate-200 text-7xl mb-8"></i>
                <h3 class="text-2xl text-slate-800 uppercase italic tracking-tighter mb-4 leading-none">Accès Restreint</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest leading-relaxed max-w-xs mx-auto">
                    Votre profil ne dispose pas des permissions de modification (M) pour le parc machines.
                </p>
                <a href="{{ route('incubators.index') }}" class="inline-block mt-10 px-12 py-5 bg-slate-900 text-white rounded-3xl text-[10px] uppercase italic no-underline shadow-lg">
                    Retour au Parc
                </a>
            </div>
            @endcan

        </div>
    </div>
</x-app-layout>