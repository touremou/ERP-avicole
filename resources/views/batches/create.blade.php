<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'🚀 ' . __('Lancer une nouvelle bande')" icon="fa-microchip" accent="indigo" :back="route('batches.index')" />
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700 text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            {{-- BLOC ERREURS --}}
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic leading-none">⚠️ {{ __("Erreurs de validation") }}</h3>
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight mt-2">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('batches.store') }}" method="POST" class="space-y-8" id="batchForm">
                @csrf

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    <div class="lg:col-span-2 space-y-8">
                        {{-- 01. IDENTIFICATION --}}
                        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-8 italic leading-none">{{ __("01. Identification & Vocation") }}</h3>
                            
                            {{-- ════ ESPÈCE + TYPE DE PRODUCTION (multiespèces) ════ --}}
                            @php $multiSpecies = $activeSpecies->count() > 1; @endphp
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left">

                                {{-- ESPÈCE --}}
                                @if($multiSpecies)
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Espèce") }}</label>
                                    <select name="species_id" id="species_selector" onchange="loadProductionTypes(this.value)"
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-black text-slate-700 shadow-inner appearance-none italic">
                                        <option value="">{{ __("-- Espèce --") }}</option>
                                        @foreach($activeSpecies as $sp)
                                        <option value="{{ $sp->id }}" data-slug="{{ $sp->slug }}"
                                            {{ old('species_id') == $sp->id ? 'selected' : '' }}>
                                            {{ $sp->icon }} {{ $sp->name_fr }}
                                        </option>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="species_slug" id="species_slug_hidden">
                                </div>
                                @else
                                {{-- Ferme mono-espèce : on pré-sélectionne sans afficher --}}
                                <input type="hidden" name="species_id" value="{{ $activeSpecies->first()?->id }}">
                                <input type="hidden" id="species_slug_fixed" value="{{ $activeSpecies->first()?->slug }}">
                                @endif

                                {{-- TYPE DE PRODUCTION --}}
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Type de production") }}</label>
                                    <select name="type" id="breeding_type" onchange="runFilters()" required
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-black text-blue-600 shadow-inner appearance-none italic">
                                        <option value="">{{ __("-- Sélectionner --") }}</option>
                                        @if(! $multiSpecies)
                                        {{-- Mono-espèce : afficher les types de la première espèce directement --}}
                                        @foreach($activeSpecies->first()?->productionTypes ?? [] as $pt)
                                        <option value="{{ $pt->slug }}" data-pt-id="{{ $pt->id }}" {{ old('type') == $pt->slug ? 'selected' : '' }}>
                                            {{ $pt->name_fr }}
                                        </option>
                                        @endforeach
                                        @else
                                        {{-- Multi-espèces : options chargées dynamiquement via JS --}}
                                        <option value="chair" {{ old('type') == 'chair' ? 'selected' : '' }}>🍗 {{ __("Poulet de chair") }}</option>
                                        <option value="ponte" {{ old('type') == 'ponte' ? 'selected' : '' }}>🥚 {{ __("Pondeuses") }}</option>
                                        <option value="poussiniere" {{ old('type') == 'poussiniere' ? 'selected' : '' }}>🐣 {{ __("Poussinière") }}</option>
                                        <option value="reproducteur" {{ old('type') == 'reproducteur' ? 'selected' : '' }}>🧬 {{ __("Reproducteurs") }}</option>
                                        @endif
                                    </select>
                                    <input type="hidden" name="production_type_id" id="production_type_id_hidden" value="{{ old('production_type_id') }}">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Souche / Race (Référentiel)") }}</label>
                                    <select name="model_name" id="model_selector" required
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-black text-blue-600 shadow-inner appearance-none italic">
                                        <option value="">{{ __("-- Sélectionner la souche --") }}</option>
                                        @foreach($normModels as $norm)
                                            <option value="{{ $norm->model_name }}"
                                                    data-type="{{ $norm->batch_type }}"
                                                    data-species="{{ $norm->species?->slug ?? '' }}"
                                                    class="model-opt"
                                                    style="display: none;">
                                                {{ $norm->model_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="text-[8px] text-slate-300 ml-4 uppercase font-bold mt-1">{{ __("* Seules les souches adaptées s'affichent") }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Protocole prophylaxie") }}</label>
                                    <select name="protocol_id" id="protocol_selector" class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-black text-blue-600 shadow-inner appearance-none italic">
                                        <option value="" selected>{{ __("-- Selectionner --") }}</option>
                                        @foreach($protocols as $protocol)
                                            <option value="{{ $protocol->id }}" 
                                                    data-type="{{ $protocol->type }}" 
                                                    class="protocol-option bg-slate-900 text-white uppercase italic text-[11px]">
                                                📜 {{ strtoupper($protocol->name) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Code Unique") }}</label>
                                    <input type="text" name="code" id="batch_code" value="{{ old('code', setting('elevage.batch_prefix_chair', 'LOT') . '-' . date('Ymd-His')) }}" required
                                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic uppercase">
                                </div>
                            </div>
                        </div>

                        {{-- 02. ARRIVÉE --}}
                        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                            <div class="flex justify-between items-center mb-8">
                                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] italic leading-none">{{ __("02. Données d'Arrivée") }}</h3>
                                <div id="density_badge" class="px-4 py-2 bg-slate-100 rounded-xl border border-slate-200 hidden">
                                    <span class="text-[8px] text-slate-400 uppercase block leading-none mb-1 text-center font-black">{{ __("Densité") }}</span>
                                    <span class="text-xs font-black text-slate-800" id="density_value">0</span> <small class="text-[8px] text-slate-500 uppercase italic">S/m²</small>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left">
                                <div id="repro_fields" class="hidden col-span-2 grid grid-cols-2 gap-8 mb-8 p-8 bg-indigo-50 rounded-[2.5rem] border border-indigo-100 italic">
                                    <div class="col-span-2 flex items-center justify-between p-4 bg-white/50 rounded-2xl border border-indigo-100">
                                        <div class="flex items-center gap-3">
                                            <div class="p-3 bg-indigo-500 rounded-xl text-white">
                                                <i class="fa-solid fa-venus-mars"></i>
                                            </div>
                                            <div>
                                                <p class="text-[8px] font-black uppercase text-slate-400 leading-none mb-1">{{ __("Ratio de Coquage") }}</p>
                                                <p class="text-xl font-black text-indigo-600 leading-none" id="ratio_display">0%</p>
                                            </div>
                                        </div>
                                        <div id="ratio_status" class="px-4 py-2 rounded-xl text-[9px] font-black uppercase italic tracking-widest bg-slate-100 text-slate-400">
                                            {{ __("En attente...") }}
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-indigo-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Nombre de Mâles") }}</label>
                                        <input type="number" min="0" name="qty_males" id="qty_males" value="0" oninput="updateTotalQty()"
                                            class="w-full p-4 bg-white rounded-2xl border-none font-black text-indigo-600 shadow-inner italic">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-indigo-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Nombre de Femelles") }}</label>
                                        <input type="number" min="0" name="qty_females" id="qty_females" value="0" oninput="updateTotalQty()"
                                            class="w-full p-4 bg-white rounded-2xl border-none font-black text-indigo-600 shadow-inner italic">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Qté Vivante (Arrivée)") }}</label>
                                    <input type="number" name="qty_alive" id="qty_alive" value="{{ old('qty_alive', 0) }}" min="1" oninput="calculateAll()" required
                                           class="w-full p-5 bg-slate-50 rounded-3xl border-none font-black text-4xl text-slate-800 shadow-inner italic appearance-none leading-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-red-500 uppercase mb-2 ml-1 italic leading-none">{{ __("Mortalité Transport") }}</label>
                                    <input type="number" name="qty_dead" id="qty_dead" value="{{ old('qty_dead', 0) }}" min="0" oninput="calculateAll()" required
                                           class="w-full p-5 bg-slate-50 rounded-3xl border-none font-black text-4xl text-slate-800 shadow-inner italic appearance-none leading-none">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-blue-600 uppercase mb-2 ml-1 italic leading-none">{{ __("Prix Unitaire") }} ({{ currency() }})</label>
                                    <input type="number" name="buy_price_per_unit" id="buy_price" value="{{ old('buy_price_per_unit', 0) }}" oninput="calculateAll()" min="0" required
                                           class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-2xl text-blue-700 shadow-inner italic leading-none">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Date d'arrivée") }}</label>
                                    <input type="date" name="arrival_date" value="{{ old('arrival_date', date('Y-m-d')) }}" required
                                           class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic leading-none">
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- SIDEBAR --}}
                    <div class="space-y-8 text-left">
                        <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl relative overflow-hidden flex flex-col border border-slate-800 text-left">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-6 italic leading-none">{{ __("03. Affectation") }}</h3>

                            <div class="space-y-6">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Bâtiment Disponible") }}</label>
                                    <select name="building_id" id="building_id" onchange="calculateAll()" required
                                            class="w-full p-4 bg-white/5 rounded-2xl border-none font-black text-blue-400 italic outline-none">
                                        <option value="">{{ __("-- Sélectionner --") }}</option>
                                        @foreach($buildings as $b)
                                            @php 
                                                $occupation = $b->batches->where('status', 'Actif')->sum('current_quantity');
                                                $libre = $b->capacity - $occupation;
                                                $surfaceOccupee = $b->batches->where('status', 'Actif')->sum('allocated_surface');
                                                $surfaceRestante = $b->surface - $surfaceOccupee;
                                            @endphp
                                            <option value="{{ $b->id }}" 
                                                    data-name="{{ $b->name }}"
                                                    data-type="{{ $b->type }}" 
                                                    data-remaining="{{ $libre }}"
                                                    data-surface="{{ $b->surface }}"
                                                    data-surface-restante="{{ $surfaceRestante }}"
                                                    class="building-opt">
                                                {{ $b->name }} | {{ __("Libre") }}: {{ $libre }} | {{ strtoupper($b->type) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="mt-4">
                                    <div class="flex items-center justify-between mb-2 ml-1">
                                        <label class="block text-[10px] font-black text-slate-400 uppercase italic leading-none">
                                            {{ __("Surface Allouée (m²)") }}
                                        </label>
                                        {{-- Icône d'info pour la sidebar sombre --}}
                                        <div class="group relative flex items-center">
                                            <i class="fas fa-info-circle text-blue-400 text-[10px] cursor-help"></i>
                                            <span id="surface_info_tooltip" class="absolute bottom-full right-0 mb-2 w-48 p-2 bg-white text-slate-900 text-[8px] font-black uppercase rounded-lg opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none shadow-xl z-50">
                                                {{ __("Surface totale bâtiment") }} : <span id="total_surface_val">0</span> m²
                                            </span>
                                        </div>
                                    </div>
                                    <input type="number" name="allocated_surface" id="allocated_surface"
                                        value="{{ old('allocated_surface') }}"
                                        step="0.1" min="0.1" oninput="calculateAll()"
                                        placeholder="{{ __('Par défaut: surface totale') }}"
                                        class="w-full p-4 bg-white/5 border border-white/10 rounded-2xl font-black text-emerald-400 italic outline-none focus:ring-2 focus:ring-emerald-500 shadow-inner">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Responsable du Lot") }}</label>
                                    <select name="employee_id" class="w-full p-4 bg-white/5 rounded-2xl border-none font-black text-slate-300 italic outline-none uppercase text-[10px]">
                                        <option value="" class="bg-slate-800 text-white">{{ __("— Aucun —") }}</option>
                                        @foreach($employees as $e)
                                            <option value="{{ $e->id }}" class="bg-slate-800 text-white">{{ $e->first_name }} {{ $e->last_name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic leading-none">{{ __("Fournisseur") }}</label>
                                    <select name="provider_id" class="w-full p-4 bg-white/5 rounded-2xl border-none font-black text-slate-300 italic outline-none uppercase text-[10px]">
                                        <option value="" class="bg-slate-800 text-white">{{ __("— Aucun —") }}</option>
                                        @foreach($providers as $p)
                                            <option value="{{ $p->id }}" class="bg-slate-800 text-white">{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="p-6 bg-white/5 rounded-3xl border border-white/10 italic text-left">
                                    <p class="text-[8px] font-black text-slate-500 uppercase mb-2 leading-none tracking-widest">{{ __("Total Facture") }}</p>
                                    <p class="text-2xl font-black text-emerald-400 tracking-tighter leading-none" id="total_cost_display">0 {{ currency() }}</p>
                                </div>
                            </div>

                            <div id="capacity_alert" class="hidden mt-6 p-4 bg-red-600/20 border border-red-600/30 rounded-2xl text-center animate-pulse">
                                <p class="text-[9px] font-black uppercase text-red-500 italic leading-none"></p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            @can('elevage.C')
                            <button type="submit" id="submitBtn" class="w-full bg-slate-900 text-white font-black py-8 rounded-[2rem] hover:bg-blue-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl">
                                <i class="fas fa-save mr-2"></i> {{ __("Initialiser la bande") }}
                            </button>
                            @else
                            <button type="button" class="w-full bg-slate-100 text-slate-400 font-black py-8 rounded-[2rem] cursor-not-allowed uppercase tracking-[0.3em] text-[10px] italic">
                                <i class="fas fa-lock mr-2"></i> {{ __("Droits de création requis") }}
                            </button>
                            @endcan

                            <a href="{{ route('batches.index') }}" class="w-full bg-white border border-slate-200 text-slate-400 font-black py-6 rounded-[2rem] hover:bg-red-50 hover:text-red-500 transition-all text-center uppercase tracking-[0.2em] text-[9px] italic flex items-center justify-center gap-2 no-underline shadow-sm">
                                <i class="fas fa-times"></i> {{ __("Annuler") }}
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @include('batches.partials.building-compatibility')

<script>
    function el(id) { return document.getElementById(id); }

    // Préfixes de code de lot pilotés par les paramètres (Paramètres > Élevage).
    const BATCH_PREFIXES = {
        chair: @json(setting('elevage.batch_prefix_chair', 'LOT')),
        ponte: @json(setting('elevage.batch_prefix_ponte', 'LOT')),
        repro: @json(setting('elevage.batch_prefix_repro', 'REP')),
    };

    // Met à jour le préfixe du code selon le type, sans écraser un code personnalisé
    // (on n'agit que si le code est encore au format auto PREFIXE-AAAAMMJJ-HHMMSS).
    function applyBatchPrefix(typeSlug) {
        const codeInput = el('batch_code');
        if (! codeInput || ! /^[A-Z]+-\d{8}-\d{6}$/.test(codeInput.value)) return;

        let prefix = BATCH_PREFIXES.chair;
        const t = (typeSlug || '').toLowerCase();
        if (t.includes('repro')) prefix = BATCH_PREFIXES.repro;
        else if (t.includes('ponte')) prefix = BATCH_PREFIXES.ponte;
        else if (t.includes('chair')) prefix = BATCH_PREFIXES.chair;

        const now = new Date();
        const p = n => String(n).padStart(2, '0');
        const stamp = `${now.getFullYear()}${p(now.getMonth() + 1)}${p(now.getDate())}-${p(now.getHours())}${p(now.getMinutes())}${p(now.getSeconds())}`;
        codeInput.value = `${prefix}-${stamp}`;
    }

    // Espèce actuellement sélectionnée (multispecies) ou fixée (mono-espèce)
    function getCurrentSpeciesSlug() {
        const speciesSelect = el('species_selector');
        if (speciesSelect) {
            return speciesSelect.options[speciesSelect.selectedIndex]?.dataset.slug || '';
        }
        return el('species_slug_fixed')?.value || '';
    }

    // Met à jour le production_type_id caché selon l'option sélectionnée
    function syncProductionTypeId() {
        const typeSelect = el('breeding_type');
        const hidden = el('production_type_id_hidden');
        if (!typeSelect || !hidden) return;
        const opt = typeSelect.options[typeSelect.selectedIndex];
        hidden.value = opt?.dataset.ptId || '';
    }

    function runFilters() {
        syncProductionTypeId();
        const selectedType = el('breeding_type').value || "";
        applyBatchPrefix(selectedType);
        const bSelect = el('building_id');
        const modelSelector = el('model_selector');
        const protocolSelector = el('protocol_selector');
        const reproFields = el('repro_fields');
        const qtyAliveInput = el('qty_alive');

        // Filtrage Souches : par type d'élevage ET par espèce.
        // Une souche sans espèce (data-species vide) est générique (toutes espèces).
        if (modelSelector) {
            const speciesSlug = getCurrentSpeciesSlug();
            modelSelector.querySelectorAll('.model-opt').forEach(opt => {
                const typeMatch = selectedType === "" || opt.dataset.type === selectedType;
                const optSpecies = opt.dataset.species || "";
                const speciesMatch = speciesSlug === "" || optSpecies === "" || optSpecies === speciesSlug;
                const isMatch = typeMatch && speciesMatch;
                opt.style.display = isMatch ? 'block' : 'none';
                opt.disabled = !isMatch;
            });
            if (modelSelector.selectedOptions[0]?.style.display === 'none') modelSelector.value = "";
        }

        // Filtrage Bâtiments
        if (bSelect) {
            bSelect.querySelectorAll('.building-opt').forEach(opt => {
                const bType = opt.dataset.type;
                const remaining = parseFloat(opt.dataset.remaining) || 0;
                const isMatch = isBuildingCompatible(bType, getCurrentSpeciesSlug(), selectedType);
                opt.disabled = !isMatch || remaining <= 0;
                opt.style.display = isMatch ? 'block' : 'none';
            });
        }

        // Filtrage Protocoles
        if (protocolSelector) {
            protocolSelector.querySelectorAll('.protocol-option').forEach(opt => {
                opt.style.display = (opt.dataset.type === selectedType) ? 'block' : 'none';
            });
        }

        // UI Reproducteurs
        if (selectedType === 'reproducteur') {
            reproFields?.classList.remove('hidden');
            if (qtyAliveInput) { qtyAliveInput.readOnly = true; qtyAliveInput.classList.add('bg-slate-100'); }
        } else {
            reproFields?.classList.add('hidden');
            if (qtyAliveInput) { qtyAliveInput.readOnly = false; qtyAliveInput.classList.remove('bg-slate-100'); }
        }
        calculateAll();
    }

    function calculateAll() {
        const qtyAlive = parseFloat(el('qty_alive')?.value) || 0;
        const qtyDead = parseFloat(el('qty_dead')?.value) || 0;
        const buyPrice = parseFloat(el('buy_price')?.value) || 0;
        const bSelect = el('building_id');
        const selectedType = el('breeding_type').value || "";
        const manualSurface = parseFloat(el('allocated_surface')?.value) || 0;
        const submitBtn = el('submitBtn');

        let remainingQty = 0;
        let bSurfaceTotale = 0;
        let bSurfaceRestante = 0;
        let bType = "";

        if (bSelect && bSelect.value) {
            const opt = bSelect.options[bSelect.selectedIndex];
            remainingQty = parseFloat(opt.dataset.remaining) || 0;
            bSurfaceTotale = parseFloat(opt.dataset.surface) || 0;
            bSurfaceRestante = parseFloat(opt.dataset.surfaceRestante) || 0;
            bType = opt.dataset.type;
            
            // Update Tooltip
            if(el('total_surface_val')) el('total_surface_val').innerText = bSurfaceTotale;
        }

        // --- CALCUL DENSITÉ ---
        const effectiveSurface = manualSurface > 0 ? manualSurface : bSurfaceTotale;
        const densityVal = el('density_value');
        const densityBadge = el('density_badge');

        if (qtyAlive > 0 && effectiveSurface > 0) {
            const density = (qtyAlive / effectiveSurface).toFixed(1);
            if(densityVal) densityVal.innerText = density;
            densityBadge?.classList.remove('hidden');
            densityBadge?.classList.toggle('bg-red-500', density > {{ setting('elevage.density_max', 15) }});
            densityBadge?.classList.toggle('text-white', density > {{ setting('elevage.density_max', 15) }});
        } else {
            densityBadge?.classList.add('hidden');
        }

        // --- TOTAL FACTURE FACTURÉ ---
        const totalFacture = (qtyAlive + qtyDead) * buyPrice;
        if(el('total_cost_display')) {
            el('total_cost_display').innerText = new Intl.NumberFormat('fr-FR').format(totalFacture) + " {{ currency() }}";
        }

        // --- VALIDATION ---
        let errorMsg = "";
        if (bSelect.value) {
            if (selectedType && !isBuildingCompatible(bType, getCurrentSpeciesSlug(), selectedType)) errorMsg = {{ Js::from(__("BÂTIMENT INCOMPATIBLE")) }};
            else if (qtyAlive > remainingQty) errorMsg = {{ Js::from(__("PLACE INSUFFISANTE (MAX:")) }} + ` ${remainingQty})`;
            else if (manualSurface > bSurfaceRestante) errorMsg = {{ Js::from(__("SURFACE INDISPONIBLE (MAX:")) }} + ` ${bSurfaceRestante.toFixed(1)} m²)`;
        } else if (!selectedType) {
            errorMsg = {{ Js::from(__("CHOISIR UN TYPE D'ÉLEVAGE")) }};
        }

        if (errorMsg) {
            submitBtn.disabled = true;
            submitBtn.classList.replace('bg-slate-900', 'bg-red-600');
            submitBtn.innerHTML = `<i class="fas fa-lock mr-2"></i> ${errorMsg}`;
        } else {
            submitBtn.disabled = false;
            submitBtn.classList.replace('bg-red-600', 'bg-slate-900');
            submitBtn.innerHTML = `<i class="fas fa-save mr-2"></i> {{ __("Initialiser la bande") }}`;
        }
    }

    function updateTotalQty() {
        const m = parseInt(el('qty_males')?.value) || 0;
        const f = parseInt(el('qty_females')?.value) || 0;
        if (el('qty_alive')) el('qty_alive').value = m + f;
        
        // Calcul du ratio pour les reproducteurs
        if (f > 0) {
            const ratio = (m / f * 100).toFixed(1);
            if(el('ratio_display')) el('ratio_display').innerText = ratio + "%";
        }
        calculateAll();
    }
    async function fillFormFromIndexedDB() {
        // On ne s'exécute que si on est en mode offline (détecté par l'absence d'options PHP)
        const bSelect = document.getElementById('building_id');
        if (!bSelect || bSelect.options.length > 1) return; 
        

        console.log("🛠️ " + {{ Js::from(__("Mode Terrain : Chargement des référentiels depuis la base locale...")) }});

        try {
            // 1. Remplissage des Bâtiments
            const buildings = await db.buildings.toArray();
            const [protocols, norms] = await Promise.all([
                db.protocols.toArray(),
                db.norms.toArray()
            ]);
            buildings.forEach(b => {
                let opt = new Option(`${b.name} | ${b.type.toUpperCase()}`, b.id);
                opt.dataset.type = b.type;
                opt.dataset.remaining = b.capacity; // Simplification pour le mode offline
                opt.dataset.surface = b.surface || 0;
                opt.classList.add('building-opt');
                bSelect.add(opt);
            });

            // 2. Remplissage des Employés (Si vous les avez mis en cache dans offline-db.js)
            // Note: Assurez-vous d'avoir ajouté 'employees' et 'providers' dans votre config Dexie
            const employees = await db.employees?.toArray() || [];
            const eSelect = document.querySelector('select[name="employee_id"]');
            employees.forEach(e => {
                eSelect.add(new Option(`${e.first_name} ${e.last_name}`, e.id));
            });

            const providers = await db.providers?.toArray() || [];
            const pSelect = document.querySelector('select[name="provider_id"]');
            providers.forEach(p => {
                pSelect.add(new Option(p.name, p.id));
            });

            // Remplissage Souches (Norms)
            const modelSelector = document.getElementById('model_selector');
            norms.forEach(n => {
                let opt = new Option(n.model_name, n.model_name);
                opt.dataset.type = n.batch_type;
                opt.className = 'model-opt';
                modelSelector.add(opt);
            });

            // Remplissage Protocoles
            const protoSelector = document.getElementById('protocol_selector');
            protocols.forEach(p => {
                let opt = new Option(p.name, p.id);
                opt.dataset.type = p.type;
                protoSelector.add(opt);
            });

            // Relancer les filtres une fois le remplissage terminé
            runFilters();

        } catch (err) {
            console.error("Erreur de chargement local :", err);
        }
    }

    window.addEventListener('DOMContentLoaded', runFilters);
    // Exécuter au chargement
    window.addEventListener('load', fillFormFromIndexedDB);
    document.getElementById('batchForm').addEventListener('submit', async function(e) {
        // Si on est hors-ligne (WAMP éteint ou réseau coupé)
        if (!navigator.onLine || {{ config('app.database_down', false) ? 'true' : 'false' }}) {
            e.preventDefault(); // On empêche l'envoi vers le serveur qui ne répondrait pas

            // 1. Extraction des données du formulaire
            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());

            // 2. Ajout des métadonnées indispensables pour la synchronisation future
            data.uuid = self.crypto.randomUUID(); // Génération d'un identifiant unique
            data.is_synced = 0; // Marqueur pour le moteur de synchro
            data.status = 'Actif';
            data.current_quantity = data.qty_alive; // Initialisation du stock
            data.initial_quantity = data.qty_alive;
            data.created_at = new Date().toISOString();
            data.updated_at = new Date().toISOString();

            try {
                // 3. Sauvegarde dans Dexie (IndexedDB)
                await db.batches.add(data);
                
                // 4. Feedback utilisateur
                alert("📦 " + {{ Js::from(__("MODE TERRAIN : La bande")) }} + " " + data.code + " " + {{ Js::from(__("a été enregistrée localement.\nElle sera synchronisée automatiquement au retour du serveur.")) }});

                // 5. Redirection vers la liste
                window.location.href = "{{ route('batches.index') }}";
            } catch (err) {
                console.error({{ Js::from(__("Erreur de stockage local :")) }}, err);
                alert({{ Js::from(__("Erreur critique lors de la sauvegarde locale.")) }});
            }
        }
    });
    // ── Chargement dynamique des types de production selon l'espèce ──
    async function loadProductionTypes(speciesId) {
        const typeSelect = document.getElementById('breeding_type');
        const slugHidden = document.getElementById('species_slug_hidden');
        const speciesSelect = document.getElementById('species_selector');

        if (slugHidden && speciesSelect) {
            const selectedOpt = speciesSelect.options[speciesSelect.selectedIndex];
            slugHidden.value = selectedOpt ? (selectedOpt.dataset.slug || '') : '';
        }

        if (!speciesId) {
            typeSelect.innerHTML = '<option value="">' + {{ Js::from(__("-- Sélectionner --")) }} + '</option>';
            return;
        }

        try {
            const resp = await fetch(`/api/species/${speciesId}/production-types`);
            const types = await resp.json();
            typeSelect.innerHTML = '<option value="">' + {{ Js::from(__("-- Type de production --")) }} + '</option>';
            types.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.slug;
                opt.textContent = t.name_fr;
                opt.dataset.cycleDays = t.cycle_days_default;
                opt.dataset.ptId = t.id;
                if ('{{ old('type') }}' === t.slug) opt.selected = true;
                typeSelect.appendChild(opt);
            });
            syncProductionTypeId();
            runFilters();
        } catch (e) {
            console.error('Erreur chargement types:', e);
        }
    }

    // Auto-charger si espèce déjà sélectionnée (retour form avec erreurs)
    document.addEventListener('DOMContentLoaded', () => {
        const speciesSel = document.getElementById('species_selector');
        if (speciesSel && speciesSel.value) {
            loadProductionTypes(speciesSel.value);
        } else {
            syncProductionTypeId();
        }
    });
    </script>
</x-app-layout>