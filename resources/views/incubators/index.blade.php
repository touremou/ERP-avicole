<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center italic font-bold" x-data>
            <div class="text-left">
                <h2 class="text-2xl font-black uppercase text-slate-800 leading-none italic tracking-tighter">⚙️ Parc Machines</h2>
                <p class="text-[10px] text-slate-400 uppercase tracking-[0.3em] mt-2 font-black leading-none">Maintenance & Configuration Industrielle</p>
            </div>
            
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('incubations.index') }}" class="flex items-center gap-2 px-5 py-3 bg-white border border-slate-200 text-slate-500 rounded-2xl font-black text-[10px] uppercase italic hover:bg-slate-50 transition shadow-sm tracking-widest no-underline">
                    <i class="fa-solid fa-arrow-left"></i> Retour
                </a>
                {{-- Permission C : Ajout de machine --}}
                @can('C')
                <button @click="$dispatch('open-add-modal')" class="bg-blue-600 text-white px-7 py-3 rounded-[1.5rem] text-[10px] font-black uppercase italic shadow-xl shadow-blue-200 hover:bg-blue-500 transition-all border-none cursor-pointer">
                    + Ajouter une Unité
                </button>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            {{-- GRILLE DES MACHINES (L) --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($incubators as $incubator)
                    @php
                        // Exploitation propre des relations refactorisées du Modèle
                        $activeIncubation = $incubator->activeIncubation;
                        $isOccupied = !is_null($activeIncubation);
                        $lastMaint = $incubator->maintenances->first();
                        $needsMaint = ($incubator->status === 'Maintenance' || $incubator->status === 'Panne');
                    @endphp

                    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden flex flex-col relative group transition-all duration-500 hover:shadow-2xl @if($isOccupied) ring-4 ring-blue-500/5 @endif">
                        
                        {{-- Badge de Performance --}}
                        <div class="absolute top-6 right-6 z-20">
                            <div class="bg-white/20 backdrop-blur-md px-4 py-2 rounded-2xl border border-white/30 text-center shadow-lg">
                                <span class="text-[7px] text-white uppercase font-black block leading-none opacity-80 mb-1">Fiabilité</span>
                                <span class="text-sm font-black text-white italic leading-none">{{ round($incubator->avg_performance ?? $incubator->global_success_rate) }}%</span>
                            </div>
                        </div>

                        {{-- Header Card (Couleur dynamique basée sur le statut) --}}
                        <div @class([
                            'p-8 text-white flex justify-between items-start transition-all duration-500 text-left',
                            'bg-blue-600 shadow-inner' => $isOccupied,
                            'bg-slate-900 shadow-inner' => !$isOccupied && !$needsMaint,
                            'bg-orange-500' => $incubator->status === 'Maintenance',
                            'bg-rose-600' => $incubator->status === 'Panne'
                        ])>
                            <div>
                                <span class="text-[9px] font-black uppercase opacity-60 tracking-widest italic leading-none block mb-2">
                                    @if($isOccupied) 
                                        <i class="fa-solid fa-sync fa-spin mr-1"></i> En Production 
                                    @elseif($needsMaint)
                                        <i class="fa-solid fa-triangle-exclamation mr-1"></i> Hors Service
                                    @else 
                                        <i class="fa-solid fa-check-circle mr-1"></i> Unité Disponible 
                                    @endif
                                </span>
                                <h3 class="text-2xl font-black uppercase italic leading-none tracking-tighter">{{ $incubator->name }}</h3>
                            </div>
                        </div>

                        {{-- Body Card --}}
                        <div class="p-8 flex-1 space-y-6 text-left italic">
                            {{-- Stats rapides --}}
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 text-center group-hover:bg-white transition-colors shadow-inner">
                                    <span class="text-[7px] text-slate-400 uppercase font-black block mb-2">Production</span>
                                    <span class="text-sm font-black text-slate-800 leading-none">{{ number_format($incubator->total_produced ?? 0) }}</span>
                                </div>
                                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 text-center group-hover:bg-white transition-colors shadow-inner">
                                    <span class="text-[7px] text-slate-400 uppercase font-black block mb-2">Cycles</span>
                                    <span class="text-sm font-black text-blue-600 leading-none">{{ $incubator->total_cycles ?? 0 }}</span>
                                </div>
                                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 text-center group-hover:bg-white transition-colors shadow-inner">
                                    <span class="text-[7px] text-slate-400 uppercase font-black block mb-2">Capacité</span>
                                    <span class="text-sm font-black text-slate-800 leading-none">{{ $incubator->capacity }}</span>
                                </div>
                            </div>

                            @if($isOccupied)
                                <div class="bg-blue-50 p-5 rounded-2xl border border-blue-100 relative overflow-hidden">
                                    <p class="text-[8px] text-blue-400 uppercase font-black leading-none mb-2 tracking-widest">Lot en incubation</p>
                                    <p class="text-base text-blue-800 font-black uppercase leading-none italic tracking-tighter">#{{ $activeIncubation->batch->code ?? 'N/A' }}</p>
                                    <i class="fa-solid fa-egg absolute -right-2 -bottom-2 text-blue-200/50 text-4xl"></i>
                                </div>
                            @endif

                            <div class="space-y-3">
                                <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest italic ml-1">Suivi de Maintenance</p>
                                @if($lastMaint)
                                    <div class="flex justify-between items-center bg-slate-50 p-4 rounded-2xl border border-slate-100 shadow-inner group-hover:bg-white transition-all">
                                        <span class="text-[11px] font-black text-slate-700 uppercase italic"><i class="fa-solid fa-wrench mr-2 text-slate-300"></i>{{ $lastMaint->type }}</span>
                                        <span class="text-[10px] text-blue-500 font-black italic">{{ $lastMaint->maintenance_date->format('d/m/Y') }}</span>
                                    </div>
                                @else
                                    <p class="text-[11px] text-slate-300 italic font-black ml-1 uppercase opacity-50">Aucun historique SAV</p>
                                @endif
                            </div>
                        </div>

                        {{-- Footer Card Actions (M/S) --}}
                        <div class="p-5 bg-slate-50 flex items-center justify-between border-t border-slate-100" x-data="{ openMaint: false }">
                            {{-- Permission M : Maintenance --}}
                            @can('M')
                            <button @click="openMaint = true" 
                                    @if($isOccupied) disabled @endif
                                    @class([
                                        'px-6 py-4 rounded-2xl text-[10px] font-black uppercase italic transition-all shadow-sm border-none cursor-pointer',
                                        'bg-slate-900 text-white hover:bg-blue-600 shadow-blue-100' => !$isOccupied,
                                        'bg-slate-200 text-slate-400 cursor-not-allowed opacity-50' => $isOccupied,
                                    ])>
                                🛠️ Déclarer SAV
                            </button>
                            @endcan

                            <div class="flex gap-2">
                                @if(!$isOccupied)
                                    @can('M')
                                    <a href="{{ route('incubators.edit', $incubator->id) }}" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-blue-500 transition-all shadow-sm no-underline" title="Modifier">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    @endcan
                                    @can('S')
                                    <form action="{{ route('incubators.destroy', $incubator->id) }}" method="POST" onsubmit="return confirm('ALERTE : Supprimer définitivement cette unité de production ? Assurez-vous qu\'elle est bien vide.')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-10 h-10 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-rose-500 transition-all shadow-sm cursor-pointer" title="Supprimer">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                    @endcan
                                @else
                                    <div class="w-10 h-10 flex items-center justify-center bg-slate-100 rounded-xl text-slate-300 cursor-help" title="Unité active : Modifications verrouillées">
                                        <i class="fa-solid fa-lock"></i>
                                    </div>
                                @endif
                            </div>

                            {{-- Modal Maintenance Interne --}}
                            <div x-show="openMaint" x-cloak x-transition.opacity class="fixed inset-0 z-[999] flex items-center justify-center bg-slate-900/90 backdrop-blur-sm p-4 text-slate-800">
                                <div @click.away="openMaint = false" class="bg-white w-full max-w-sm rounded-[3.5rem] p-12 shadow-2xl relative italic text-left">
                                    <button @click="openMaint = false" type="button" class="absolute top-8 right-10 text-slate-300 hover:text-rose-500 transition-all border-none bg-transparent cursor-pointer">
                                        <i class="fa-solid fa-circle-xmark text-3xl"></i>
                                    </button>
                                    <h3 class="text-2xl font-black uppercase italic mb-8 border-b border-slate-50 pb-6 tracking-tighter">SAV : {{ $incubator->name }}</h3>
                                    
                                    {{-- L'URL pointe vers l'action de sauvegarde de la maintenance --}}
                                    <form action="{{ route('incubators.maintenance.store', $incubator->id) }}" method="POST" class="space-y-4">
                                        @csrf
                                        <div class="space-y-2">
                                            <label class="text-[9px] font-black text-slate-400 uppercase italic ml-2">Date d'intervention</label>
                                            <input type="date" name="maintenance_date" value="{{ date('Y-m-d') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black italic text-sm outline-none shadow-inner">
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-[9px] font-black text-slate-400 uppercase italic ml-2">Type d'opération</label>
                                            <select name="type" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black italic text-xs shadow-inner outline-none appearance-none cursor-pointer">
                                                <option value="Désinfection">Nettoyage / Désinfection</option>
                                                <option value="Étalonnage">Étalonnage</option>
                                                <option value="Entretien">Entretien Préventif</option>
                                                <option value="Réparation">Réparation Corrective</option>
                                            </select>
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-[9px] font-black text-slate-400 uppercase italic ml-2">Description des travaux</label>
                                            <textarea name="description" rows="2" placeholder="Détails de l'intervention..." required 
                                                class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black italic text-xs shadow-inner outline-none resize-none"></textarea>
                                        </div>

                                        <input type="hidden" name="performed_by" value="{{ Auth::user()->name }}">

                                        <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl uppercase italic shadow-xl tracking-[0.2em] hover:bg-blue-600 transition-all border-none cursor-pointer mt-4">
                                            Valider SAV
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 🚀 MODAL DE CRÉATION GLOBAL (Permission C) --}}
    @can('C')
    <div x-data="{ openAdd: false }" 
         @open-add-modal.window="openAdd = true" 
         x-show="openAdd" 
         x-cloak 
         class="fixed inset-0 z-[9999] overflow-y-auto">
        
        <div class="fixed inset-0 bg-slate-900/95 backdrop-blur-md transition-opacity"></div>

        <div class="flex min-h-full items-center justify-center p-6">
            <div @click.away="openAdd = false" 
                 x-show="openAdd"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 class="relative bg-white w-full max-w-md rounded-[4rem] p-12 shadow-2xl text-left italic font-bold">
                
                <button @click="openAdd = false" type="button" class="absolute top-10 right-12 text-slate-300 hover:text-rose-500 transition-all border-none bg-transparent cursor-pointer">
                    <i class="fa-solid fa-circle-xmark text-3xl"></i>
                </button>

                <h3 class="text-3xl font-black uppercase mb-3 leading-tight italic text-slate-800 tracking-tighter">
                    Nouvelle <span class="text-blue-600">Unité</span>
                </h3>
                <p class="text-[10px] text-slate-400 uppercase mb-10 tracking-[0.3em] font-black">Configuration de l'incubateur</p>

                <form action="{{ route('incubators.store') }}" method="POST" class="space-y-8">
                    @csrf
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase italic ml-3">Désignation Machine</label>
                        <input type="text" name="name" placeholder="EX: COUVEUSE-B4-01" required 
                               class="w-full bg-slate-50 border-none rounded-3xl p-5 font-black italic shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10 text-slate-800 text-base uppercase">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase italic ml-3">Capacité (Nb d'œufs)</label>
                        <input type="number" min="0" name="capacity" placeholder="Capacité maximale" required 
                               class="w-full bg-slate-50 border-none rounded-3xl p-5 font-black italic shadow-inner outline-none focus:ring-4 focus:ring-blue-500/10 text-slate-800 text-base">
                    </div>
                    <button type="submit" class="w-full bg-slate-900 text-white font-black py-6 rounded-3xl uppercase italic shadow-2xl tracking-[0.2em] hover:bg-blue-600 transition-all border-none cursor-pointer">
                        Enregistrer l'unité
                    </button>
                </form>
            </div>
        </div>
    </div>
    @endcan
</x-app-layout>