<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row items-center justify-between gap-6 text-left">
            <div class="flex items-center gap-5">
                <a href="{{ route('employees.index') }}" class="flex items-center justify-center w-12 h-12 bg-white border border-slate-200 text-slate-400 hover:text-slate-800 rounded-2xl transition-all shadow-sm group no-underline">
                    <i class="fas fa-times group-hover:rotate-90 transition-transform text-sm"></i>
                </a>

                <div>
                    <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        🗄️ Archives & Corbeille
                    </h2>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-2 italic leading-none">
                        Restauration des entités système supprimées
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-6">
                <span class="hidden lg:block text-[9px] font-black text-slate-400 uppercase italic tracking-[0.3em] animate-pulse">
                    ● Mode Restauration Système Actif
                </span>

                {{-- BOUTON VIDER TOUT --}}
                @if($employees->count() > 0 || $buildings->count() > 0 || $providers->count() > 0)
                    <form action="{{ route('trash.clearAll') }}" method="POST" onsubmit="return confirm('⚠️ ACTION IRRÉVERSIBLE : Voulez-vous vraiment supprimer DÉFINITIVEMENT tous les éléments de la corbeille ?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="flex items-center gap-3 px-6 py-4 bg-slate-900 text-rose-500 rounded-2xl text-[10px] font-black uppercase italic tracking-widest hover:bg-rose-600 hover:text-white transition-all shadow-2xl group border-none cursor-pointer">
                            <i class="fas fa-dumpster-fire group-hover:animate-bounce"></i>
                            Purger la corbeille
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-16">
            
            {{-- SECTION RH --}}
            <section class="text-left">
                <div class="flex items-center gap-4 mb-8">
                    <div class="h-8 w-1.5 bg-blue-600 rounded-full"></div>
                    <h3 class="text-[11px] font-black text-slate-800 uppercase tracking-[0.2em] italic">Ressources Humaines : Agents Archivés</h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @forelse($employees as $e)
                        <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm flex justify-between items-center group hover:border-blue-400 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-[1.2rem] bg-slate-50 flex items-center justify-center text-blue-600 text-lg shadow-inner">
                                    <i class="fas fa-user-ninja"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-800 uppercase leading-none tracking-tighter">{{ $e->first_name }} {{ $e->last_name }}</p>
                                    <p class="text-[9px] text-slate-400 uppercase italic mt-2 leading-none font-black">Effacé le {{ $e->deleted_at->format('d/m/Y') }}</p>
                                </div>
                            </div>
                            <form action="{{ route('trash.restore', ['employee', $e->id]) }}" method="POST">
                                @csrf
                                <button type="submit" class="bg-slate-900 text-white w-10 h-10 rounded-xl hover:bg-blue-600 transition-all shadow-xl flex items-center justify-center group/btn border-none cursor-pointer">
                                    <i class="fas fa-history text-xs group-hover/btn:rotate-[-45deg] transition-transform"></i>
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="col-span-full bg-slate-50/50 border-2 border-dashed border-slate-100 rounded-[3rem] py-16 text-center">
                            <i class="fas fa-user-slash text-slate-200 text-4xl mb-4 block"></i>
                            <p class="text-[10px] text-slate-300 uppercase italic tracking-widest font-black">RH : Index Corbeille Vierge</p>
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- SECTION INFRASTRUCTURE --}}
            <section class="text-left">
                <div class="flex items-center gap-4 mb-8">
                    <div class="h-8 w-1.5 bg-emerald-500 rounded-full"></div>
                    <h3 class="text-[11px] font-black text-slate-800 uppercase tracking-[0.2em] italic">Infrastructure : Bâtiments Déconnectés</h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @forelse($buildings as $b)
                        <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm flex justify-between items-center group hover:border-emerald-400 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-[1.2rem] bg-slate-50 flex items-center justify-center text-emerald-600 text-lg shadow-inner">
                                    <i class="fas fa-warehouse"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-800 uppercase leading-none tracking-tighter">{{ $b->name }}</p>
                                    <p class="text-[9px] text-slate-400 uppercase italic mt-2 leading-none font-black tracking-widest">Zone Hors-ligne</p>
                                </div>
                            </div>
                            <form action="{{ route('trash.restore', ['building', $b->id]) }}" method="POST">
                                @csrf
                                <button type="submit" class="bg-slate-900 text-white w-10 h-10 rounded-xl hover:bg-emerald-600 transition-all shadow-xl flex items-center justify-center group/btn border-none cursor-pointer">
                                    <i class="fas fa-plug text-xs"></i>
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="col-span-full bg-slate-50/50 border-2 border-dashed border-slate-100 rounded-[3rem] py-16 text-center">
                            <i class="fas fa-map-marked-alt text-slate-200 text-4xl mb-4 block"></i>
                            <p class="text-[10px] text-slate-300 uppercase italic tracking-widest font-black">Logistique : Aucune unité détectée</p>
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- SECTION LOGISTIQUE --}}
            <section class="text-left">
                <div class="flex items-center gap-4 mb-8">
                    <div class="h-8 w-1.5 bg-orange-500 rounded-full"></div>
                    <h3 class="text-[11px] font-black text-slate-800 uppercase tracking-[0.2em] italic">Partenariats : Fournisseurs Suspendus</h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @forelse($providers as $p)
                        <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm flex justify-between items-center group hover:border-orange-400 transition-all duration-300">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-[1.2rem] bg-slate-50 flex items-center justify-center text-orange-600 text-lg shadow-inner">
                                    <i class="fas fa-truck-loading"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-800 uppercase leading-none tracking-tighter">{{ $p->name }}</p>
                                    <p class="text-[9px] text-slate-400 uppercase italic mt-2 leading-none font-black tracking-widest">Rupture : {{ $p->deleted_at->diffForHumans() }}</p>
                                </div>
                            </div>
                            <form action="{{ route('trash.restore', ['provider', $p->id]) }}" method="POST">
                                @csrf
                                <button type="submit" class="bg-slate-900 text-white w-10 h-10 rounded-xl hover:bg-orange-600 transition-all shadow-xl flex items-center justify-center group/btn border-none cursor-pointer">
                                    <i class="fas fa-sync-alt text-xs"></i>
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="col-span-full bg-slate-50/50 border-2 border-dashed border-slate-100 rounded-[3rem] py-16 text-center">
                            <i class="fas fa-box-open text-slate-200 text-4xl mb-4 block"></i>
                            <p class="text-[10px] text-slate-300 uppercase italic tracking-widest font-black">Fournisseurs : Flux archives vide</p>
                        </div>
                    @endforelse
                </div>
            </section>

        </div>
    </div>
</x-app-layout>