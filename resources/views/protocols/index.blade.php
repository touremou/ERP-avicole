<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                {{-- Permission L : Lecture du registre santé --}}
                <a href="{{ route('health.index') }}" class="group flex items-center justify-center w-12 h-12 bg-white border border-slate-200 text-slate-400 hover:text-slate-800 rounded-2xl transition-all shadow-sm no-underline">
                    <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform"></i>
                </a>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">
                        📜 {{ __("Bibliothèque des Protocoles") }}
                    </h2>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-2 italic">{{ __("Standards de prophylaxie AviSmart") }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4 w-full md:w-auto">
                {{-- RECHERCHE (L) --}}
                <div class="relative flex-1 md:w-48">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input type="text" id="protocolSearch" placeholder="{{ __('RECHERCHER...') }}"
                           class="w-full pl-10 pr-4 py-3 bg-slate-100 border-none rounded-2xl text-[10px] font-black uppercase italic focus:ring-4 focus:ring-blue-500/10 transition-all placeholder:text-slate-300 outline-none">
                </div>

                {{-- IMPORTATION (C/M) --}}
                @can('elevage.C')
                <form action="{{ route('protocols.import') }}" method="POST" enctype="multipart/form-data" id="importForm" class="relative group">
                    @csrf
                    <label for="protocol_file" id="dropzone"
                           class="flex items-center gap-3 px-6 py-3 bg-white border-2 border-dashed border-slate-200 text-slate-500 rounded-2xl text-[10px] font-black uppercase italic hover:border-blue-500 hover:text-blue-600 transition-all cursor-pointer shadow-sm relative overflow-hidden h-[46px]">
                        <i class="fa-solid fa-cloud-arrow-up text-emerald-500 text-sm group-hover:scale-110 transition-transform"></i>
                        <span id="dropzone-text">{{ __("Importer (JSON)") }}</span>
                        <input type="file" name="protocol_file" id="protocol_file" class="hidden" accept=".json" onchange="handleFileSelect(this)">
                        <div id="upload-loader" class="hidden absolute inset-0 bg-white flex items-center justify-center">
                            <i class="fa-solid fa-circle-notch animate-spin text-blue-600"></i>
                        </div>
                    </label>
                </form>

                {{-- CRÉATION (C) --}}
                <button onclick="document.getElementById('modal-protocol').classList.remove('hidden')" class="px-6 py-3 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/20 whitespace-nowrap h-[46px] border-none cursor-pointer">
                    <i class="fa-solid fa-plus-circle mr-2 text-blue-400"></i> {{ __("Créer un modèle") }}
                </button>
                @endcan
            </div>
        </div>
    </x-slot>

    {{-- FEEDBACK ACTIONS --}}
    @if(session('success'))
        <div class="max-w-7xl mx-auto px-4 mt-6">
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 px-8 py-5 rounded-[2.5rem] text-[10px] font-black uppercase italic tracking-widest animate-fadeIn shadow-sm">
                <i class="fa-solid fa-check-double mr-2"></i> {{ session('success') }}
            </div>
        </div>
    @endif

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="protocolGrid">
                @forelse($protocols as $protocol)
                    <div class="protocol-card bg-white p-10 rounded-[4rem] border border-slate-100 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 group relative overflow-hidden flex flex-col justify-between min-h-[440px]" data-name="{{ strtoupper($protocol->name) }}">
                        
                        {{-- TOOLBAR SECRÈTE (M/S) --}}
                        <div class="absolute top-8 left-8 flex flex-col gap-3 opacity-0 group-hover:opacity-100 transition-all transform -translate-x-4 group-hover:translate-x-0 z-20">
                            <button onclick="openPreview({{ json_encode($protocol->name) }}, {{ json_encode($protocol->steps) }})" class="w-10 h-10 bg-white text-blue-500 rounded-xl hover:bg-blue-500 hover:text-white transition-all flex items-center justify-center shadow-lg" title="{{ __('Aperçu rapide') }}">
                                <i class="fa-solid fa-eye text-xs"></i>
                            </button>
                            @can('elevage.M')
                            <a href="{{ route('protocols.export', $protocol->id) }}" class="w-11 h-11 bg-white text-emerald-600 rounded-2xl hover:bg-emerald-600 hover:text-white transition-all flex items-center justify-center shadow-xl text-decoration-none" title="{{ __('Exporter') }}">
                                <i class="fa-solid fa-download text-sm"></i>
                            </a>
                            @endcan
                            @can('elevage.S')
                            <form action="{{ route('protocols.destroy', $protocol->id) }}" method="POST" onsubmit="return confirm(@json(__('ALERTE : Supprimer ce standard master ?')))">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-11 h-11 bg-white text-rose-600 rounded-2xl hover:bg-rose-600 hover:text-white transition-all flex items-center justify-center shadow-xl border-none cursor-pointer">
                                    <i class="fa-solid fa-trash-can text-sm"></i>
                                </button>
                            </form>
                            @endcan
                        </div>

                        <div class="relative text-left italic">
                            <div class="flex justify-between items-start mb-8">
                                <span @class([
                                    'px-5 py-2.5 rounded-2xl text-[9px] font-black uppercase italic shadow-sm tracking-[0.2em]',
                                    'bg-blue-600 text-white' => $protocol->type == 'chair',
                                    'bg-purple-600 text-white' => $protocol->type == 'ponte',
                                    'bg-emerald-600 text-white' => $protocol->type == 'poussiniere',
                                    'bg-orange-600 text-white' => $protocol->type == 'reproducteur',
                                    'bg-slate-900 text-white' => !in_array($protocol->type, ['chair', 'ponte', 'poussiniere', 'reproducteur']),
                                ])>
                                    {{ $protocol->type ?? __('STANDARD') }}
                                </span>
                                <div class="text-right">
                                    <p class="text-2xl font-black text-slate-800 tracking-tighter leading-none italic">{{ $protocol->steps_count }}</p>
                                    <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest mt-1 italic leading-none">{{ __("Étapes") }}</p>
                                </div>
                            </div>

                            <h3 class="text-2xl font-black text-slate-800 uppercase tracking-tighter mb-4 leading-tight group-hover:text-blue-600 transition-colors italic">
                                {{ $protocol->name }}
                            </h3>
                            
                            <div class="flex flex-wrap gap-2 mb-6">
                                <span class="px-3 py-1.5 bg-slate-50 text-slate-500 rounded-xl text-[8px] font-black uppercase border border-slate-100 italic">{{ __("SOUCHE:") }} {{ $protocol->strain ?? __('MIXTE') }}</span>
                                <span class="px-3 py-1.5 bg-blue-50 text-blue-500 rounded-xl text-[8px] font-black uppercase border border-blue-100 italic">{{ __("OFFICIEL AVISMART") }}</span>
                            </div>

                            <p class="text-[11px] text-slate-400 uppercase leading-relaxed mb-8 line-clamp-3 font-black italic opacity-80">
                                {{ $protocol->description ?? __("Modèle de prophylaxie standardisé pour le suivi sanitaire rigoureux.") }}
                            </p>
                        </div>

                        <div class="space-y-6 relative text-left">
                            <div class="flex items-center gap-4 py-6 border-y border-slate-50">
                                <div class="flex -space-x-3">
                                    @for($i=0; $i<min($protocol->active_batches_count, 3); $i++)
                                        <div class="w-10 h-10 rounded-2xl bg-slate-100 border-4 border-white flex items-center justify-center shadow-lg transform hover:-translate-y-1 transition-transform cursor-help">
                                            <i class="fa-solid fa-feather text-xs text-slate-400"></i>
                                        </div>
                                    @endfor
                                    @if($protocol->active_batches_count == 0)
                                        <div class="w-10 h-10 rounded-2xl bg-slate-50 border-4 border-dashed border-slate-200 flex items-center justify-center text-slate-300 text-[10px]">
                                            <i class="fa-solid fa-hourglass-empty"></i>
                                        </div>
                                    @endif
                                </div>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest italic leading-tight">
                                    {{ __("Appliqué à") }} <span class="text-slate-900">{{ $protocol->active_batches_count }} {{ Str::plural('Lot', $protocol->active_batches_count) }}</span>
                                </p>
                            </div>

                            <div class="flex gap-4">
                                @can('elevage.M')
                                <a href="{{ route('protocols.edit', $protocol->id) }}" class="flex-1 text-center py-5 bg-slate-900 text-white rounded-[1.5rem] text-[10px] font-black uppercase tracking-[0.2em] hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/10 no-underline italic">
                                    {{ __("Configurer") }}
                                </a>
                                <a href="{{ route('protocols.show', $protocol->id) }}" title="{{ __('Details') }}" class="w-14 py-5 bg-slate-100 text-slate-400 rounded-[1.5rem] hover:bg-slate-600 hover:text-white transition-all shadow-sm flex items-center justify-center border-none cursor-pointer">
                                    <i class="fa-solid fa-info-circle text-sm"></i>
                                </a>
                                <form action="{{ route('protocols.duplicate', $protocol->id) }}" method="POST" class="shrink-0">
                                    @csrf
                                    <button type="submit" title="{{ __('Dupliquer') }}" class="w-14 py-5 bg-slate-100 text-slate-400 rounded-[1.5rem] hover:bg-emerald-600 hover:text-white transition-all shadow-sm flex items-center justify-center border-none cursor-pointer">
                                        <i class="fa-solid fa-clone text-sm"></i>
                                    </button>
                                </form>
                                @endcan
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-40 border-4 border-dashed border-slate-100 rounded-[5rem] text-center bg-slate-50/50 italic flex flex-col items-center">
                        <i class="fa-solid fa-layer-group text-6xl text-slate-200 mb-6"></i>
                        <p class="text-slate-400 uppercase text-xs font-black tracking-[0.4em] italic">{{ __("Bibliothèque Vierge") }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- MODAL APERÇU RAPIDE --}}
    <div id="modal-preview" class="hidden fixed inset-0 bg-slate-900/95 backdrop-blur-xl z-[60] flex items-center justify-center p-6 italic font-bold">
        <div class="bg-white w-full max-w-2xl rounded-[4rem] shadow-2xl overflow-hidden border border-white/20">
            <div class="p-12 text-left">
                <div class="flex justify-between items-start mb-10">
                    <div>
                        <h3 id="preview-title" class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none font-black"></h3>
                        <p class="text-[10px] font-black text-blue-500 uppercase tracking-[0.2em] mt-2 italic font-black">{{ __("Contenu détaillé du programme") }}</p>
                    </div>
                    <button onclick="document.getElementById('modal-preview').classList.add('hidden')" class="w-12 h-12 bg-slate-50 rounded-2xl text-slate-400 hover:text-red-500 transition-colors flex items-center justify-center border-none shadow-inner">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>
                <div id="preview-steps" class="space-y-3 max-h-[400px] overflow-y-auto pr-4 no-scrollbar italic uppercase font-black text-[10px]"></div>
            </div>
        </div>
    </div>

    {{-- MODAL CRÉATION --}}
    <div id="modal-protocol" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-4 italic font-bold">
        <div class="bg-white w-full max-w-lg rounded-[4rem] shadow-2xl p-12 relative overflow-hidden border border-white/20 text-left">
            <h3 class="text-3xl font-black uppercase italic tracking-tighter mb-2 text-slate-800 font-black">{{ __("Nouveau Modèle") }}</h3>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mb-12 italic font-black">{{ __("Initialisation du standard") }}</p>
            <form action="{{ route('protocols.store') }}" method="POST" class="space-y-8">
                @csrf
                <div class="space-y-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-4 tracking-[0.2em] mb-3 block italic leading-none font-black">{{ __("Nom du protocole") }}</label>
                        <input type="text" name="name" placeholder="{{ __('EX: CHAIR EXPORT 45J') }}" class="w-full p-6 bg-slate-50 rounded-[2rem] border-none shadow-inner font-black uppercase text-slate-700 italic" required>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-blue-500 uppercase ml-4 tracking-[0.2em] mb-3 block italic leading-none font-black">{{ __("Type d'élevage") }}</label>
                        <select name="type" class="w-full p-6 bg-slate-50 rounded-[2rem] border-none shadow-inner font-black text-blue-600 appearance-none italic uppercase" required>
                            <option value="">{{ __("-- Sélectionner --") }}</option>
                            @include('protocols.partials.type-options', ['productionTypes' => $productionTypes, 'selected' => old('type')])
                        </select>
                    </div>
                </div>
                <div class="flex gap-4 pt-6 italic">
                    <button type="button" onclick="document.getElementById('modal-protocol').classList.add('hidden')" class="flex-1 py-6 text-[10px] font-black uppercase text-slate-400 italic border-none bg-transparent font-black">{{ __("Annuler") }}</button>
                    <button type="submit" class="flex-[2] py-6 bg-slate-900 text-white rounded-[2rem] text-[10px] font-black uppercase italic shadow-2xl hover:bg-blue-600 transition-all font-black">{{ __("Créer") }} <i class="fa-solid fa-arrow-right ml-2 text-blue-400"></i></button>
                </div>
            </form>
        </div>
    </div>

    <style>
        #dropzone { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .animate-fadeIn { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>

    <script>
    // --- LOGIQUE FILTRAGE ---
    document.getElementById('protocolSearch').addEventListener('input', function(e) {
        const search = e.target.value.toUpperCase();
        document.querySelectorAll('.protocol-card').forEach(card => {
            const name = card.getAttribute('data-name');
            card.style.display = name.includes(search) ? 'flex' : 'none';
        });
    });
    
    // --- FONCTION APERÇU (CORRIGÉE) ---
    function openPreview(name, steps) {
        const modal = document.getElementById('modal-preview');
        const title = document.getElementById('preview-title');
        const container = document.getElementById('preview-steps');

        title.innerText = name;
        container.innerHTML = '';

        if (!steps || steps.length === 0) {
            container.innerHTML = `<div class="p-6 bg-slate-50 rounded-2xl text-slate-400 italic">${@json(__('Aucune étape configurée pour ce protocole.'))}</div>`;
        } else {
            // 💡 CORRECTION : Utilisation de day_number (et non a.day)
            steps.sort((a, b) => a.day_number - b.day_number);

            steps.forEach(step => {
                const stepEl = document.createElement('div');
                stepEl.className = "p-5 bg-slate-50 rounded-2xl border border-slate-100 flex items-center gap-4 transition-all hover:bg-white hover:shadow-md";
                stepEl.innerHTML = `
                    <div class="w-12 h-12 bg-blue-600 text-white rounded-xl flex flex-col items-center justify-center shrink-0 shadow-lg shadow-blue-200">
                        <span class="text-[8px] leading-none mb-1 opacity-70">${@json(__('JOUR'))}</span>
                        <span class="text-xs leading-none font-black">${step.day_number}</span>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between items-start mb-1">
                            <h4 class="text-slate-800 font-black">${step.action_name || @json(__('INTERVENTION'))}</h4>
                            <span class="px-2 py-1 bg-blue-100 text-blue-600 rounded-lg text-[7px] font-black">${step.type || @json(__('SANTÉ'))}</span>
                        </div>
                        <p class="text-[9px] text-slate-400 font-bold lowercase">${@json(__('Méthode'))} : ${step.method || @json(__('Non spécifiée'))}</p>
                    </div>
                `;
                container.appendChild(stepEl);
            });
        }

        modal.classList.remove('hidden');
    }

    // --- GESTION IMPORT (JSON) ---
    function handleFileSelect(input) {
        if (input.files && input.files[0]) {
            document.getElementById('dropzone-text').innerText = @json(__("VÉRIFICATION..."));
            document.getElementById('upload-loader').classList.remove('hidden');
            setTimeout(() => { input.form.submit(); }, 800);
        }
    }
</script>
</x-app-layout>