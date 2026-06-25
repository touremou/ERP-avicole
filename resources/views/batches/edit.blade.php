<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="text-left">
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    {{ __("Modifier le Lot") }} : <span class="text-blue-600">{{ $batch->code }}</span>
                </h2>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">
                    {{ __("Effectif actuel") }} : <span class="text-emerald-600 font-black">{{ $batch->current_quantity }} {{ __("sujets") }}</span>
                    — {{ __("Mortalité cumulée") }} : <span class="text-red-500 font-black">{{ $batch->total_mortality }}</span>
                </p>
            </div>
            <a href="{{ route('batches.show', $batch->id) }}" class="group flex items-center text-slate-400 hover:text-red-500 transition text-sm font-bold uppercase tracking-widest leading-none no-underline">
                <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i> {{ __("Retour") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700 text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl text-left italic">
                    <h3 class="font-black uppercase text-xs mb-2 italic">{{ __("Erreur de validation") }}</h3>
                    <ul class="text-sm font-bold list-disc ml-5 opacity-90">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('batches.update', $batch->id) }}" method="POST" class="space-y-8" id="editBatchForm">
                @csrf 
                @method('PUT')

                @php
                    $isEditable = $batch->isActive();
                    $isRepro = in_array(old('type', $batch->type), ['repro', 'reproducteur']);
                    $batchSpecies = $batch->species;
                @endphp

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 text-left">
                    
                    {{-- 01. LOGISTIQUE & IDENTITÉ --}}
                    <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 space-y-6">
                        <h3 class="text-[10px] font-black uppercase text-blue-500 tracking-[0.2em] flex items-center italic">
                            <i class="fas fa-map-marker-alt mr-2"></i> {{ __("01. Logistique & Identité") }}
                        </h3>
                        
                        <div class="space-y-4">
                            {{-- ESPÈCE — affichage seul (non modifiable après création) --}}
                            @if($batchSpecies)
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Espèce") }}</label>
                                <div class="w-full p-4 bg-slate-100 rounded-2xl font-black text-slate-500 shadow-inner italic flex items-center gap-2">
                                    <span>{{ $batchSpecies->icon }}</span> {{ $batchSpecies->name_fr }}
                                </div>
                                <p class="text-[8px] text-slate-300 ml-4 uppercase font-bold mt-1">{{ __("* L'espèce n'est plus modifiable après la création du lot") }}</p>
                            </div>
                            @endif
                            <input type="hidden" name="species_id" value="{{ $batch->species_id }}">
                            <input type="hidden" name="production_type_id" id="production_type_id" value="{{ $batch->production_type_id }}">
                            <input type="hidden" id="species_slug_fixed" value="{{ $batchSpecies->slug ?? '' }}">

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Type d'Élevage") }}</label>
                                <select name="type" id="type_selector" required
                                    class="w-full p-4 bg-slate-50 rounded-2xl font-black outline-none border-none text-blue-600 shadow-inner appearance-none italic">
                                    @if($batchSpecies && $batchSpecies->productionTypes->isNotEmpty())
                                        @foreach($batchSpecies->productionTypes as $pt)
                                            <option value="{{ $pt->slug }}" data-pt-id="{{ $pt->id }}" {{ old('type', $batch->type) == $pt->slug ? 'selected' : '' }}>
                                                {{ $pt->name_fr }}
                                            </option>
                                        @endforeach
                                    @else
                                        @foreach(['chair' => 'POULET DE CHAIR', 'ponte' => 'PONDEUSES', 'poussiniere' => 'POUSSINIÈRE', 'reproducteur' => 'REPRODUCTEURS', 'engraissement' => 'ENGRAISSEMENT'] as $val => $label)
                                            <option value="{{ $val }}" {{ old('type', $batch->type) == $val ? 'selected' : '' }}>{{ __($label) }}</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Souche / Race") }}</label>
                                <select name="model_name" id="model_selector" required
                                        class="w-full p-4 bg-slate-50 shadow-inner rounded-2xl font-black outline-none border-none text-blue-600 appearance-none italic">
                                    @foreach($normModels as $norm)
                                        <option value="{{ $norm->model_name }}"
                                                data-type="{{ $norm->batch_type }}"
                                                class="model-opt"
                                                {{ old('model_name', $batch->model_name) == $norm->model_name ? 'selected' : '' }}>
                                            {{ $norm->model_name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="text-[8px] text-slate-300 ml-4 uppercase font-bold mt-1">{{ __("* Seules les souches adaptées au type d'élevage sélectionné s'affichent") }}</p>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Bâtiment Affecté") }}</label>
                                <select name="building_id" id="building_id" required
                                    class="w-full p-4 bg-slate-50 rounded-2xl font-bold border-none shadow-inner text-slate-700 italic">
                                    @foreach($buildings as $b)
                                        @php
                                            $occ = $b->batches->where('status', \App\Models\Batch::STATUS_ACTIF)->where('id', '!=', $batch->id)->sum('current_quantity');
                                            $dispo = $b->capacity - $occ;
                                        @endphp
                                        <option value="{{ $b->id }}"
                                                data-type="{{ $b->type }}"
                                                data-remaining="{{ $dispo }}"
                                                class="building-opt"
                                                {{ old('building_id', $batch->building_id) == $b->id ? 'selected' : '' }}>
                                            {{ $b->name }} ({{ __("Libre") }}: {{ $dispo }} — {{ strtoupper($b->type) }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Surface Allouée (m²)") }}</label>
                                <input type="number" name="allocated_surface" value="{{ old('allocated_surface', $batch->allocated_surface) }}"
                                    step="0.1" min="0.1"
                                    class="w-full p-4 bg-slate-50 rounded-2xl font-black text-emerald-600 border-none shadow-inner italic">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Responsable Technique") }}</label>
                                <select name="employee_id" required class="w-full p-4 bg-slate-50 rounded-2xl font-bold border-none shadow-inner text-slate-700 italic">
                                    @foreach($employees as $e)
                                        <option value="{{ $e->id }}" {{ old('employee_id', $batch->employee_id) == $e->id ? 'selected' : '' }}>{{ $e->first_name }} {{ $e->last_name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Fournisseur") }}</label>
                                <select name="provider_id" required class="w-full p-4 bg-slate-50 rounded-2xl font-bold border-none shadow-inner text-slate-700 italic">
                                    @foreach($providers as $p)
                                        <option value="{{ $p->id }}" {{ old('provider_id', $batch->provider_id) == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- 02. DONNÉES DU LOT --}}
                    <div class="lg:col-span-2 space-y-8">
                        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden">
                            <div class="flex justify-between items-center mb-8">
                                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] italic leading-none">{{ __("02. Paramètres du Lot") }}</h3>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                {{-- Section reproducteurs (mâles/femelles) --}}
                                <div id="repro_fields" class="{{ !$isRepro ? 'hidden' : '' }} col-span-2 grid grid-cols-2 gap-8 mb-4 p-8 bg-indigo-50 rounded-[2.5rem] border border-indigo-100 italic">
                                    <div class="col-span-2 flex items-center justify-between p-4 bg-white/50 rounded-2xl border border-indigo-100 shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="p-3 bg-indigo-500 rounded-xl text-white shadow-lg">
                                                <i class="fa-solid fa-venus-mars"></i>
                                            </div>
                                            <div>
                                                <p class="text-[8px] font-black uppercase text-slate-400 leading-none mb-1">{{ __("Ratio de Coquage") }}</p>
                                                <p class="text-xl font-black text-indigo-600 leading-none" id="ratio_display">
                                                    {{ $batch->qty_females > 0 ? number_format(($batch->qty_males / $batch->qty_females) * 100, 1) : 0 }}%
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-indigo-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Nombre de Mâles") }}</label>
                                        <input type="number" min="0" name="qty_males" id="qty_males" value="{{ old('qty_males', $batch->qty_males ?? 0) }}"
                                            class="repro-input w-full p-4 bg-white rounded-2xl border-none font-black text-indigo-600 shadow-inner italic">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-indigo-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Nombre de Femelles") }}</label>
                                        <input type="number" min="0" name="qty_females" id="qty_females" value="{{ old('qty_females', $batch->qty_females ?? 0) }}"
                                            class="repro-input w-full p-4 bg-white rounded-2xl border-none font-black text-indigo-600 shadow-inner italic">
                                    </div>
                                </div>

                                {{-- CHAMPS EN LECTURE SEULE (effectifs figés) --}}
                                <div>
                                    <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic leading-none">
                                        Qté Initiale (Arrivée)
                                        <span class="text-slate-300 text-[8px] ml-2">non modifiable</span>
                                    </label>
                                    <div class="w-full p-5 bg-slate-100 rounded-3xl font-black text-4xl text-slate-600 italic leading-none cursor-not-allowed">
                                        {{ number_format($batch->initial_quantity) }}
                                    </div>
                                    {{-- PAS de champ name= → la valeur n'est PAS envoyée au serveur --}}
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-red-500 uppercase mb-2 ml-1 italic leading-none">
                                        Mortalité Transport
                                        <span class="text-slate-300 text-[8px] ml-2">non modifiable</span>
                                    </label>
                                    <div class="w-full p-5 bg-slate-100 rounded-3xl font-black text-4xl text-slate-600 italic leading-none cursor-not-allowed">
                                        {{ number_format($batch->qty_dead) }}
                                    </div>
                                </div>

                                {{-- Effectif actuel (informatif) --}}
                                <div>
                                    <label class="block text-[10px] font-black text-blue-500 uppercase mb-2 ml-1 italic leading-none">
                                        Effectif Vivant Actuel
                                        <span class="text-slate-300 text-[8px] ml-2">calculé automatiquement</span>
                                    </label>
                                    <div class="w-full p-5 bg-emerald-50 rounded-3xl font-black text-4xl text-emerald-700 italic leading-none border border-emerald-100">
                                        {{ number_format($batch->current_quantity) }}
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-[10px] font-black text-blue-600 uppercase mb-2 ml-1 italic leading-none">Prix Unitaire ({{ currency() }})</label>
                                    <input type="number" name="buy_price_per_unit" id="buy_price" value="{{ old('buy_price_per_unit', (int)$batch->buy_price_per_unit) }}" min="0" required
                                           class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-2xl text-blue-700 shadow-inner italic leading-none">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">Date d'arrivée</label>
                                    <input type="date" name="arrival_date" value="{{ old('arrival_date', $batch->arrival_date ? $batch->arrival_date->format('Y-m-d') : '') }}" required
                                           class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic leading-none text-center text-sm">
                                </div>

                                <div class="col-span-1 md:col-span-2">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">Statut du Lot</label>
                                    <select name="status" required class="w-full p-5 bg-slate-50 rounded-2xl font-black border-none text-slate-700 shadow-inner italic appearance-none">
                                        @foreach(\App\Models\Batch::EDITABLE_STATUSES as $statusOption)
                                            <option value="{{ $statusOption }}" {{ old('status', $batch->status) == $statusOption ? 'selected' : '' }}>{{ \Illuminate\Support\Str::upper($statusOption) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        {{-- 03. STRATÉGIE SANITAIRE --}}
                        <div class="bg-slate-900 p-10 rounded-[3.5rem] shadow-2xl relative overflow-hidden italic text-left">
                            <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-[0.3em] mb-6 flex items-center leading-none">
                                <i class="fas fa-shield-virus mr-2 text-blue-500"></i> 03. Stratégie Sanitaire
                            </h3>
                            <select name="protocol_id" class="w-full p-5 bg-white/5 border border-white/10 rounded-2xl font-black text-blue-400 outline-none italic appearance-none shadow-2xl">
                                <option value="" class="bg-slate-900 text-slate-500">-- AUCUN PROTOCOLE --</option>
                                @foreach($protocols as $protocol)
                                    <option value="{{ $protocol->id }}" 
                                        data-type="{{ $protocol->type }}" 
                                        {{ old('protocol_id', $batch->protocol_id) == $protocol->id ? 'selected' : '' }} 
                                        class="protocol-option bg-slate-900 text-white uppercase italic text-[11px]">
                                        {{ strtoupper($protocol->name) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- ACTIONS --}}
                        <div class="flex flex-col md:flex-row gap-4 pt-6">
                            <a href="{{ route('batches.show', $batch->id) }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-6 rounded-[2rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-widest text-[10px] italic transition flex items-center justify-center no-underline">
                                Annuler
                            </a>
                            
                            @can('elevage.M')
                            <button type="submit" id="submitBtn" class="flex-[2] bg-slate-900 text-white font-black py-6 rounded-[2rem] hover:bg-blue-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl">
                                <i class="fas fa-sync-alt mr-3"></i> Enregistrer les modifications
                            </button>
                            @else
                            <div class="flex-[2] bg-slate-100 text-slate-400 font-black py-6 rounded-[2rem] text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-2 cursor-not-allowed">
                                <i class="fas fa-lock"></i> Modification restreinte
                            </div>
                            @endcan
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @include('batches.partials.building-compatibility')

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const typeSelector = document.getElementById('type_selector');
            const reproFields = document.getElementById('repro_fields');
            const productionTypeIdInput = document.getElementById('production_type_id');

            function toggleRepro() {
                if (typeSelector.value === 'reproducteur') {
                    reproFields?.classList.remove('hidden');
                } else {
                    reproFields?.classList.add('hidden');
                }
            }

            // Met à jour le production_type_id caché selon l'option sélectionnée
            function syncProductionTypeId() {
                if (!productionTypeIdInput) return;
                const selectedOpt = typeSelector.options[typeSelector.selectedIndex];
                productionTypeIdInput.value = selectedOpt?.dataset.ptId || '';
            }

            // Filtre la Souche/Race et le Bâtiment selon le type d'élevage
            // sélectionné — réplique le filtrage déjà appliqué à la création
            // (batches/create.blade.php), absent jusqu'ici de l'édition, ce
            // qui permettait de choisir n'importe quelle souche/bâtiment.
            function runFilters() {
                const selectedType = typeSelector.value || "";

                const modelSelector = document.getElementById('model_selector');
                if (modelSelector) {
                    modelSelector.querySelectorAll('.model-opt').forEach(opt => {
                        const isMatch = selectedType === "" || opt.dataset.type === selectedType;
                        const isCurrent = opt.selected;
                        opt.style.display = (isMatch || isCurrent) ? 'block' : 'none';
                        opt.disabled = !isMatch && !isCurrent;
                    });
                }

                const bSelect = document.getElementById('building_id');
                if (bSelect) {
                    bSelect.querySelectorAll('.building-opt').forEach(opt => {
                        const isCurrent = opt.selected;
                        const isMatch = isBuildingCompatible(opt.dataset.type, document.getElementById('species_slug_fixed')?.value || '', selectedType);
                        opt.style.display = (isMatch || isCurrent) ? 'block' : 'none';
                        opt.disabled = !isMatch && !isCurrent;
                    });
                }
            }

            typeSelector?.addEventListener('change', toggleRepro);
            typeSelector?.addEventListener('change', syncProductionTypeId);
            typeSelector?.addEventListener('change', runFilters);
            runFilters();

            // Ratio reproducteurs
            document.querySelectorAll('.repro-input').forEach(input => {
                input.addEventListener('input', function() {
                    const m = parseInt(document.getElementById('qty_males')?.value) || 0;
                    const f = parseInt(document.getElementById('qty_females')?.value) || 0;
                    const display = document.getElementById('ratio_display');
                    if (display && f > 0) {
                        display.innerText = (m / f * 100).toFixed(1) + '%';
                    }
                });
            });
        });
    </script>
</x-app-layout>
