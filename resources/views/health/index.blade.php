<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🩺 Registre Sanitaire & Prophylaxie')" :subtitle="__('Historique des soins, vaccins et interventions techniques')" icon="fa-stethoscope" accent="purple">
            <x-slot name="actions">
                {{-- Permission L : Protocoles --}}
                @can('elevage.M')
                <a href="{{ route('protocols.index') }}" class="px-5 py-3 bg-white text-slate-600 border border-slate-200 rounded-2xl text-[10px] font-black uppercase italic tracking-widest hover:bg-slate-50 transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-clipboard-list mr-2 text-purple-500"></i> {{ __("Protocoles") }}
                </a>
                @endcan


                {{-- NOUVEAU : ALERTE PATHOLOGIE (S'ouvre via Alpine) --}}
                @can('elevage.C')
                <button type="button" x-data @click="$dispatch('open-pathology-modal')" class="px-5 py-3 bg-rose-100 text-rose-600 rounded-2xl text-[10px] font-black uppercase italic tracking-widest hover:bg-rose-600 hover:text-white transition-all shadow-sm border border-rose-200">
                    <i class="fa-solid fa-microscope mr-2"></i> {{ __("Signaler Anomalie") }}
                </button>
                @endcan

                {{-- Permission C : Nouvelle Intervention --}}
                @can('elevage.C')
                <a href="{{ route('health.create') }}" class="px-6 py-3 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/20 no-underline">
                    <i class="fa-solid fa-plus-circle mr-2 text-blue-400"></i> {{ __("Nouvelle Intervention") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            {{-- ALERTES PROTOCOLE DYNAMIQUES (Mode Carrousel Horizontal) --}}
            @if(!empty($alerts))
                <div class="bg-rose-50/50 p-6 md:p-8 rounded-[3rem] border border-rose-100 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-rose-200 text-rose-600 rounded-full flex items-center justify-center shadow-inner animate-pulse">
                                <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-rose-700 uppercase italic tracking-tighter leading-none mb-1">{{ __("Urgences Sanitaires") }}</h3>
                                <p class="text-[10px] text-rose-400 font-black uppercase tracking-widest italic leading-none">{{ count($alerts) }} {{ __("intervention(s) en retard") }}</p>
                            </div>
                        </div>

                        {{-- Indicateur de scroll visuel pour desktop --}}
                        <div class="hidden md:flex gap-2 text-rose-300">
                            <i class="fa-solid fa-arrow-left-long"></i>
                            <span class="text-[9px] uppercase font-black tracking-widest italic">{{ __("Défiler") }}</span>
                            <i class="fa-solid fa-arrow-right-long"></i>
                        </div>
                    </div>

                    {{-- Conteneur Scrollable Horizontal --}}
                    <div class="flex gap-4 overflow-x-auto pb-4 pt-2 snap-x snap-mandatory [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]">
                        @foreach($alerts as $alert)
                            <div class="min-w-[280px] md:min-w-[320px] flex-shrink-0 bg-white border-b-4 border-rose-500 p-6 rounded-[2rem] shadow-md snap-start transition-all hover:-translate-y-1 flex flex-col justify-between group">
                                <div class="mb-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <span class="px-3 py-1 bg-rose-100 text-rose-700 rounded-xl text-[9px] font-black uppercase italic tracking-widest">{{ __("Lot #") }}{{ $alert['batch_code'] }}</span>
                                        <span class="text-[10px] font-black text-rose-400 italic">{{ $alert['delay'] }}{{ __("j de retard") }}</span>
                                    </div>
                                    <h4 class="text-sm font-black text-slate-800 uppercase italic leading-tight">{{ $alert['step_name'] }}</h4>
                                    <p class="text-[10px] text-slate-400 font-bold mt-2">{{ __("Prévu le :") }} {{ \Carbon\Carbon::parse($alert['due_date'])->format('d/m/Y') }}</p>
                                </div>
                                
                                @can('elevage.C')
                                <a href="{{ route('health.create', ['batch_id' => $alert['batch_id'], 'product_name' => $alert['step_name'], 'type' => $alert['step_type'], 'intervention_date' => now()->format('Y-m-d')]) }}" 
                                   class="w-full py-3 bg-rose-50 group-hover:bg-rose-600 text-rose-600 group-hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest flex items-center justify-center transition-all no-underline italic">
                                    {{ __("Régulariser") }} <i class="fa-solid fa-arrow-right ml-2"></i>
                                </a>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- DASHBOARD SANTÉ --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 text-left italic font-black uppercase">
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    <p class="text-[9px] text-slate-400 tracking-widest mb-2 leading-none">{{ __("Total Interventions") }}</p>
                    <h3 class="text-4xl text-slate-800 leading-none tracking-tighter">{{ $checks->total() }}</h3>
                    <div class="absolute -right-2 -bottom-2 opacity-5 text-5xl">💉</div>
                </div>
                <div class="bg-purple-600 p-7 rounded-[2.5rem] text-white shadow-xl shadow-purple-200 relative overflow-hidden border-b-4 border-purple-800">
                    <p class="text-[9px] text-purple-200 tracking-widest mb-2 leading-none">{{ __("Dernier Vaccin") }}</p>
                    <h3 class="text-lg leading-tight tracking-tighter italic">
                        {{ $checks->firstWhere('type', 'Vaccin')->product_name ?? __("NÉANT") }}
                    </h3>
                    <i class="fa-solid fa-syringe absolute -right-3 -bottom-3 text-white/10 text-7xl"></i>
                </div>
                <div class="bg-blue-600 p-7 rounded-[2.5rem] text-white shadow-xl shadow-blue-200 relative overflow-hidden border-b-4 border-blue-800">
                    <p class="text-[9px] text-blue-200 tracking-widest mb-2 leading-none">{{ __("Budget (Page)") }}</p>
                    <h3 class="text-3xl leading-none tracking-tighter">{{ number_format($checks->sum('cost'), 0, ',', ' ') }} <small class="text-[10px]">{{ currency() }}</small></h3>
                    <i class="fa-solid fa-vial absolute -right-3 -bottom-3 text-white/10 text-7xl"></i>
                </div>

                {{-- NOUVEAU : CAS PATHOLOGIQUES EN ATTENTE --}}
                <div class="bg-rose-600 p-7 rounded-[2.5rem] text-white shadow-xl shadow-rose-200 relative overflow-hidden border-b-4 border-rose-800">
                    <p class="text-[9px] text-rose-200 tracking-widest mb-2 leading-none">{{ __("Diagnostics en attente") }}</p>
                    <h3 class="text-3xl leading-none tracking-tighter">
                        {{ $pendingIncidentsCount ?? 0 }} <small class="text-[10px] uppercase font-black tracking-widest">{{ __("Cas") }}</small>
                    </h3>
                    <div class="mt-2">
                        <a href="{{ route('health.incidents.index') }}" class="text-[8px] bg-rose-800 px-3 py-1 rounded-lg hover:bg-white hover:text-rose-600 transition-colors no-underline">
                            {{ __("Voir les alertes") }} <i class="fa-solid fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    <i class="fa-solid fa-truck-medical absolute -right-3 -bottom-3 text-white/10 text-7xl"></i>
                </div>
            </div>

            {{-- FILTRES FONCTIONNELS (L) --}}
            <form action="{{ route('health.index') }}" method="GET" class="flex flex-col md:flex-row items-center justify-between px-6 gap-6 italic">
                <div class="flex flex-wrap gap-4">
                    <select name="batch_id" onchange="this.form.submit()" class="bg-white border-none rounded-xl text-[10px] font-black uppercase italic focus:ring-4 focus:ring-blue-500/10 py-3 pl-5 pr-10 shadow-sm cursor-pointer appearance-none">
                        <option value="">{{ __("Tous les lots") }}</option>
                        @foreach($batches as $batch)
                            <option value="{{ $batch->id }}" {{ request('batch_id') == $batch->id ? 'selected' : '' }}>{{ __("LOT #") }}{{ $batch->code }}</option>
                        @endforeach
                    </select>

                    <select name="type" onchange="this.form.submit()" class="bg-white border-none rounded-xl text-[10px] font-black uppercase italic focus:ring-4 focus:ring-blue-500/10 py-3 pl-5 pr-10 shadow-sm cursor-pointer appearance-none">
                        <option value="">{{ __("Tous les types") }}</option>
                        @foreach(['Vaccin', 'Traitement', 'Vitamine', 'Désinfection'] as $type)
                            <option value="{{ $type }}" {{ request('type') == $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>

                    @if(request()->anyFilled(['batch_id', 'type']))
                        <a href="{{ route('health.index') }}" class="text-[9px] font-black text-rose-500 uppercase flex items-center hover:underline no-underline italic">
                            <i class="fa-solid fa-circle-xmark mr-1"></i> {{ __("Réinitialiser") }}
                        </a>
                    @endif
                </div>
                <p class="text-[9px] text-slate-400 uppercase font-black italic tracking-widest">
                    {{ $checks->total() }} {{ __("Acte(s) enregistré(s)") }}
                </p>
            </form>

            {{-- TABLEAU REGISTRE --}}
            <div class="bg-white rounded-[3.5rem] border border-slate-100 shadow-xl shadow-slate-200/40 overflow-hidden text-left italic">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100 italic">
                            <th class="px-10 py-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __("Chronologie") }}</th>
                            <th class="px-8 py-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __("Sujets (Lot)") }}</th>
                            <th class="px-8 py-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __("Intervention / Produit") }}</th>
                            <th class="px-8 py-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __("Posologie") }}</th>
                            <th class="px-10 py-6 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 text-right">{{ __("Budget / Action") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 uppercase font-bold italic">
                        @forelse($checks as $check)
                        <tr class="hover:bg-slate-50 transition-all group">
                            <td class="px-10 py-7">
                                <p class="text-xs font-black text-slate-800 leading-none italic">
                                    {{ \Carbon\Carbon::parse($check->intervention_date)->translatedFormat('d F Y') }}
                                </p>
                                <p class="text-[9px] text-slate-400 mt-2 italic font-black leading-none">
                                    <i class="fa-regular fa-clock mr-1"></i> {{ \Carbon\Carbon::parse($check->intervention_date)->diffForHumans() }}
                                </p>
                            </td>
                            <td class="px-8 py-7">
                                <div class="flex flex-col">
                                    <span class="text-blue-600 font-black text-base tracking-tighter leading-none italic">
                                        #{{ $check->batch->code }}
                                    </span>
                                    <span class="text-[8px] text-slate-400 font-black uppercase tracking-widest mt-1 italic">
                                        {{ __("Bât:") }} {{ $check->batch->building->name ?? __("NÉANT") }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-8 py-7">
                                <div class="flex items-center gap-4">
                                    <div @class([
                                        'w-11 h-11 rounded-2xl flex items-center justify-center text-white shadow-lg transform group-hover:rotate-12 transition-transform',
                                        'bg-purple-600' => $check->type === 'Vaccin',
                                        'bg-blue-600' => $check->type === 'Traitement',
                                        'bg-yellow-500' => $check->type === 'Vitamine',
                                        'bg-slate-600' => $check->type === 'Désinfection',
                                    ])>
                                        <i @class([
                                            'fa-solid text-sm',
                                            'fa-syringe' => $check->type === 'Vaccin',
                                            'fa-pills' => $check->type === 'Traitement',
                                            'fa-vial-virus' => $check->type === 'Vitamine',
                                            'fa-spray-can-sparkles' => $check->type === 'Désinfection',
                                        ])></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-slate-800 leading-none mb-1 italic tracking-tight">{{ $check->product_name }}</p>
                                        <p class="text-[9px] text-slate-400 font-black tracking-widest leading-none italic">{{ $check->type }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-7">
                                <div class="px-4 py-2 bg-slate-50 rounded-xl border border-slate-100 inline-block italic">
                                    <p class="text-[10px] text-slate-700 font-black leading-none italic">
                                        <i class="fa-solid fa-droplet mr-1 text-blue-500"></i> {{ $check->mode_administration }}
                                    </p>
                                </div>
                            </td>
                            <td class="px-10 py-7 text-right">
                                <div class="flex items-center justify-end gap-6 italic">
                                    <div class="text-right">
                                        <p class="font-black text-slate-900 text-base leading-none italic tracking-tighter">
                                            {{ number_format($check->cost, 0, ',', ' ') }}
                                        </p>
                                        <p class="text-[9px] text-slate-300 font-black italic tracking-[0.2em] leading-none mt-1">{{ currency() }}</p>
                                    </div>
                                    
                                    {{-- Permission M/S : Actions --}}
                                    @can('elevage.M')
                                    <div class="flex items-center gap-2 opacity-100 can-hover:opacity-0 can-hover:group-hover:opacity-100 transition-opacity">
                                        <a href="{{ route('health.edit', $check->id) }}" class="w-10 h-10 flex items-center justify-center rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-all no-underline shadow-sm shadow-blue-100">
                                            <i class="fa-solid fa-pen-nib text-xs"></i>
                                        </a>
                                        @can('elevage.S')
                                        <form action="{{ route('health.destroy', $check->id) }}" method="POST" onsubmit="return confirm(@json(__("ALERTE SÉCURITÉ : Supprimer cet acte sanitaire ?")))">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="w-10 h-10 flex items-center justify-center rounded-xl bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition-all border-none outline-none shadow-sm shadow-rose-100 cursor-pointer">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-8 py-32 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="w-20 h-20 bg-slate-50 rounded-[2.5rem] flex items-center justify-center text-slate-200 mb-6 border border-slate-100 rotate-3">
                                        <i class="fa-solid fa-notes-medical text-4xl"></i>
                                    </div>
                                    <p class="text-slate-400 uppercase text-[10px] tracking-[0.4em] font-black italic">
                                        {{ __("Registre Sanitaire Vierge") }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-10 italic">
                {{ $checks->links() }}
            </div>
        </div>
    </div>

    {{-- MODALE ALPINE POUR LE SIGNALEMENT SANITAIRE --}}
    <div x-data="{ showHealthModal: false }" 
         @open-pathology-modal.window="showHealthModal = true"
         x-show="showHealthModal" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4" 
         style="display: none;"
         x-transition>
        
        <div @click.away="showHealthModal = false" class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden text-left border border-slate-100 transform transition-all">
            
            <div class="bg-rose-600 p-8 text-white flex justify-between items-center relative overflow-hidden">
                <i class="fa-solid fa-virus absolute -left-4 -top-4 text-[6rem] opacity-10"></i>
                <div class="relative z-10">
                    <h3 class="text-xl font-black uppercase tracking-tighter italic leading-none">{{ __("Signalement Sanitaire") }}</h3>
                    <p class="text-[10px] text-rose-200 font-bold uppercase tracking-widest mt-1">{{ __("Alerte Vétérinaire & Autopsie") }}</p>
                </div>
                <button @click="showHealthModal = false" class="text-white hover:text-rose-200 relative z-10"><i class="fa-solid fa-xmark text-2xl"></i></button>
            </div>

            <form action="{{ route('health.incidents.store') }}" method="POST" enctype="multipart/form-data" class="p-8 space-y-6">
                @csrf
                
                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Lot concerné *") }}</label>
                    <select name="batch_id" required class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs uppercase shadow-inner cursor-pointer text-slate-700">
                        <option value="">{{ __("Sélectionner un lot en cours...") }}</option>
                        @foreach($batches as $batch)
                            <option value="{{ $batch->id }}">{{ __("LOT #") }}{{ $batch->code }} ({{ __("Bât:") }} {{ $batch->building->name ?? __("N/A") }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Cadavres *") }}</label>
                        <input type="number" name="mortality_count" required min="1" placeholder="{{ __('Nb.') }}" class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs text-rose-600 shadow-inner focus:ring-2 focus:ring-rose-500 text-center">
                    </div>
                    <div>
                        <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Photo Autopsie") }}</label>
                        <input type="file" name="photo" accept="image/jpeg, image/png" capture="environment" class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] shadow-inner file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:font-black file:bg-rose-100 file:text-rose-700 hover:file:bg-rose-200 cursor-pointer">
                    </div>
                </div>

                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Symptômes & Observations *") }}</label>
                    <textarea name="symptoms" required rows="3" placeholder="{{ __('Ex: Diarrhée sanguinolente, prostration, râles respiratoires...') }}" class="w-full bg-slate-50 border-none rounded-xl p-4 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-rose-500 shadow-inner"></textarea>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-slate-900 text-white py-5 rounded-xl font-black uppercase tracking-widest transition-all hover:bg-rose-600 text-[10px] shadow-lg flex items-center justify-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> {{ __("Transmettre au Vétérinaire") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>