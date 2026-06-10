<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('planning.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform text-xs"></i>
                <span class="text-[10px] font-black uppercase italic tracking-widest leading-none">Retour</span>
            </a>
            <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                📅 Planifier une nouvelle bande
            </h2>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700 text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic leading-none">⚠️ Erreurs de validation</h3>
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight mt-2">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('planning.store') }}" method="POST" id="planForm">
                @csrf

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-8">

                        {{-- 01. IDENTIFICATION & VOCATION --}}
                        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 text-left">
                            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-8 italic leading-none">01. Identification & Vocation</h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Type d'élevage *</label>
                                    <select name="batch_type" id="breeding_type" onchange="runFilters()" required
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-indigo-500 outline-none font-black text-indigo-600 shadow-inner appearance-none italic">
                                        <option value="">-- Sélectionner --</option>
                                        @foreach($productionTypes->groupBy(fn($pt) => $pt->species->name_fr ?? 'Autres') as $speciesLabel => $types)
                                            <optgroup label="{{ strtoupper($speciesLabel) }}">
                                                @foreach($types as $pt)
                                                    <option value="{{ $pt->slug }}"
                                                            data-cycle="{{ $pt->cycle_days_default ?? 42 }}"
                                                            data-species-id="{{ $pt->species_id }}"
                                                            data-species-slug="{{ $pt->species->slug ?? '' }}"
                                                            data-pt-id="{{ $pt->id }}"
                                                            {{ old('batch_type') == $pt->slug && (string) old('production_type_id') === (string) $pt->id ? 'selected' : '' }}>
                                                        {{ $pt->species->icon ?? '' }} {{ $pt->name_fr }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="species_id" id="species_id_hidden" value="{{ old('species_id') }}">
                                    <input type="hidden" name="production_type_id" id="production_type_id_hidden" value="{{ old('production_type_id') }}">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Souche / Race (Référentiel)</label>
                                    <select name="model_name" id="model_selector"
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-indigo-500 outline-none font-black text-indigo-600 shadow-inner appearance-none italic">
                                        <option value="">-- Sélectionner la souche --</option>
                                        @foreach($normModels as $norm)
                                            <option value="{{ $norm->model_name }}" data-type="{{ $norm->batch_type }}" class="model-opt" style="display: none;">
                                                {{ $norm->model_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="text-[8px] text-slate-300 ml-4 uppercase font-bold mt-1">* Seules les souches adaptées au type s'affichent</p>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Protocole prophylaxie</label>
                                    <select name="protocol_id" id="protocol_selector"
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-indigo-500 outline-none font-black text-indigo-600 shadow-inner appearance-none italic">
                                        <option value="">-- Optionnel --</option>
                                        @foreach($protocols as $protocol)
                                            <option value="{{ $protocol->id }}" data-type="{{ $protocol->type }}" class="protocol-option">
                                                📜 {{ strtoupper($protocol->name) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Date d'arrivée prévue *</label>
                                    <input type="date" name="planned_arrival_date" id="arrival_date" value="{{ old('planned_arrival_date') }}" required onchange="calculateDates()"
                                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic">
                                </div>
                            </div>
                        </div>

                        {{-- 02. QUANTITÉ & BÂTIMENT --}}
                        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 text-left">
                            <div class="flex justify-between items-center mb-8">
                                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] italic leading-none">02. Quantité & Affectation</h3>
                                <div id="density_badge" class="px-4 py-2 bg-slate-100 rounded-xl border border-slate-200 hidden">
                                    <span class="text-[8px] text-slate-400 uppercase block leading-none mb-1 text-center font-black">Densité</span>
                                    <span class="text-xs font-black text-slate-800" id="density_value">0</span> <small class="text-[8px] text-slate-500 uppercase italic">S/m²</small>
                                </div>
                            </div>

                            {{-- REPRODUCTEURS : Mâles + Femelles --}}
                            <div id="repro_fields" class="hidden mb-8 p-8 bg-indigo-50 rounded-[2.5rem] border border-indigo-100">
                                <div class="flex items-center justify-between p-4 bg-white/50 rounded-2xl border border-indigo-100 mb-6">
                                    <div class="flex items-center gap-3">
                                        <div class="p-3 bg-indigo-500 rounded-xl text-white"><i class="fa-solid fa-venus-mars"></i></div>
                                        <div>
                                            <p class="text-[8px] font-black uppercase text-slate-400 leading-none mb-1">Ratio de Coquage</p>
                                            <p class="text-xl font-black text-indigo-600 leading-none" id="ratio_display">0%</p>
                                        </div>
                                    </div>
                                    <div id="ratio_status" class="px-4 py-2 rounded-xl text-[9px] font-black uppercase italic tracking-widest bg-slate-100 text-slate-400">En attente...</div>
                                </div>
                                <div class="grid grid-cols-2 gap-8">
                                    <div>
                                        <label class="block text-[10px] font-black text-indigo-500 uppercase mb-2 ml-1 italic">Nombre de Mâles</label>
                                        <input type="number" min="0" id="qty_males" value="0" oninput="updateReproTotal()"
                                               class="w-full p-4 bg-white rounded-2xl border-none font-black text-indigo-600 shadow-inner italic">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-indigo-500 uppercase mb-2 ml-1 italic">Nombre de Femelles</label>
                                        <input type="number" min="0" id="qty_females" value="0" oninput="updateReproTotal()"
                                               class="w-full p-4 bg-white rounded-2xl border-none font-black text-indigo-600 shadow-inner italic">
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic leading-none">Quantité Prévue *</label>
                                    <input type="number" name="planned_quantity" id="planned_qty" value="{{ old('planned_quantity', 0) }}" min="1" required oninput="calculateAll()"
                                           class="w-full p-5 bg-slate-50 rounded-3xl border-none font-black text-4xl text-slate-800 shadow-inner italic appearance-none leading-none">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Bâtiment *</label>
                                    <select name="building_id" id="building_id" onchange="calculateAll()" required
                                            class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-indigo-500 outline-none font-black text-indigo-600 shadow-inner appearance-none italic">
                                        <option value="">-- Sélectionner --</option>
                                        @foreach($buildings as $b)
                                            @php
                                                $occupation = $b->occupied_qty ?? 0;
                                                $libre = $b->capacity - $occupation;
                                            @endphp
                                            <option value="{{ $b->id }}"
                                                    data-type="{{ $b->type }}"
                                                    data-remaining="{{ $libre }}"
                                                    data-surface="{{ $b->surface ?? 0 }}"
                                                    data-capacity="{{ $b->capacity }}"
                                                    class="building-opt">
                                                {{ $b->name }} | Libre: {{ $libre }}/{{ $b->capacity }} | {{ strtoupper($b->type) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="mt-6">
                                <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Fournisseur poussins</label>
                                <select name="provider_id" class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-600 shadow-inner appearance-none italic outline-none">
                                    <option value="">-- Optionnel --</option>
                                    @foreach($providers as $p)
                                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mt-6">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic">Notes</label>
                                <textarea name="notes" rows="2" placeholder="Informations complémentaires..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none italic"></textarea>
                            </div>

                            {{-- ALERTE CAPACITÉ --}}
                            <div id="capacity_alert" class="hidden mt-6 p-4 bg-red-600/10 border border-red-200 rounded-2xl animate-pulse">
                                <p class="text-[9px] font-black uppercase text-red-600 italic leading-none" id="capacity_msg"></p>
                            </div>
                        </div>
                    </div>

                    {{-- SIDEBAR : DATES CALCULÉES + RÉSUMÉ --}}
                    <div class="space-y-8">
                        {{-- DATES AUTO --}}
                        <div class="bg-indigo-50 p-8 rounded-[3rem] border border-indigo-200" id="dates_panel" style="display:none;">
                            <h3 class="text-[10px] font-black text-indigo-600 uppercase tracking-[0.2em] mb-6 italic leading-none flex items-center gap-2">
                                <i class="fa-solid fa-calculator"></i> Cycle calculé
                            </h3>
                            <div class="space-y-3">
                                <div class="bg-white p-4 rounded-2xl flex justify-between items-center">
                                    <div><p class="text-[8px] font-black text-red-500 uppercase">Commander avant</p><p class="text-[8px] text-slate-400">J-56</p></div>
                                    <p class="text-sm font-black text-slate-900" id="dt_order">—</p>
                                </div>
                                <div class="bg-white p-4 rounded-2xl flex justify-between items-center">
                                    <div><p class="text-[8px] font-black text-emerald-500 uppercase">Arrivée poussins</p><p class="text-[8px] text-slate-400">J0</p></div>
                                    <p class="text-sm font-black text-emerald-600" id="dt_arrival">—</p>
                                </div>
                                <div class="bg-white p-4 rounded-2xl flex justify-between items-center">
                                    <div><p class="text-[8px] font-black text-amber-500 uppercase" id="dt_end_label">Abattage</p><p class="text-[8px] text-slate-400" id="dt_end_j">—</p></div>
                                    <p class="text-sm font-black text-slate-900" id="dt_end">—</p>
                                </div>
                                <div class="bg-white p-4 rounded-2xl flex justify-between items-center">
                                    <div><p class="text-[8px] font-black text-blue-500 uppercase">Vide sanitaire</p><p class="text-[8px] text-slate-400">21 jours</p></div>
                                    <p class="text-sm font-black text-slate-900" id="dt_void">—</p>
                                </div>
                                <div class="bg-white p-4 rounded-2xl flex justify-between items-center">
                                    <div><p class="text-[8px] font-black text-slate-500 uppercase">Bât. disponible</p></div>
                                    <p class="text-sm font-black text-blue-600" id="dt_free">—</p>
                                </div>
                            </div>
                        </div>

                        {{-- RÉSUMÉ --}}
                        <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl border border-slate-800">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-6 italic leading-none">Résumé</h3>
                            <div class="space-y-4">
                                <div class="flex justify-between"><span class="text-[9px] text-slate-500 uppercase font-black">Type</span><span class="text-xs font-black text-white" id="sum_type">—</span></div>
                                <div class="flex justify-between"><span class="text-[9px] text-slate-500 uppercase font-black">Bâtiment</span><span class="text-xs font-black text-white" id="sum_building">—</span></div>
                                <div class="flex justify-between"><span class="text-[9px] text-slate-500 uppercase font-black">Quantité</span><span class="text-xs font-black text-emerald-400" id="sum_qty">0</span></div>
                                <div class="flex justify-between"><span class="text-[9px] text-slate-500 uppercase font-black">Densité</span><span class="text-xs font-black text-white" id="sum_density">—</span></div>
                                <div class="flex justify-between"><span class="text-[9px] text-slate-500 uppercase font-black">Durée cycle</span><span class="text-xs font-black text-amber-400" id="sum_cycle">—</span></div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            @can('planning.C')
                            <button type="submit" id="submitBtn" class="w-full bg-indigo-600 text-white font-black py-8 rounded-[2rem] hover:bg-indigo-700 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                                <i class="fas fa-calendar-plus mr-2"></i> Enregistrer la planification
                            </button>
                            @endcan
                            <a href="{{ route('planning.index') }}" class="w-full bg-white border border-slate-200 text-slate-400 font-black py-6 rounded-[2rem] hover:bg-red-50 hover:text-red-500 transition-all text-center uppercase tracking-[0.2em] text-[9px] italic flex items-center justify-center gap-2 no-underline shadow-sm">
                                <i class="fas fa-times"></i> Annuler
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

<script>
const CYCLES = {
    chair: {{ setting('elevage.cycle_chair', 42) }},
    ponte: {{ setting('elevage.cycle_ponte', 540) }},
    poussiniere: {{ setting('elevage.cycle_poussiniere', 90) }},
    reproducteur: {{ setting('elevage.cycle_reproducteur', 450) }}
   };
const CYCLE_LABELS = { chair: 'Abattage', ponte: 'Réforme', poussiniere: 'Transfert', reproducteur: 'Réforme' };

// Types de bâtiments compatibles par espèce (aligné sur le lancement de lot).
const SPECIES_BUILDING_TYPES = {
    poulet:  ['chair', 'ponte', 'poussiniere', 'reproducteur'],
    dinde:   ['chair', 'reproducteur'],
    pintade: ['chair', 'ponte'],
    caille:  ['chair', 'ponte'],
    canard:  ['chair'],
    pigeon:  ['chair'],
    mouton:  ['bergerie'],
    chevre:  ['chevrerie'],
    lapin:   ['lapiniere'],
    porc:    ['porcherie'],
    tilapia: ['bassin'],
    carpe:   ['bassin'],
    silure:  ['bassin'],
};

function el(id) { return document.getElementById(id); }

// Cycle (jours) du type de production sélectionné — piloté par les données
// (cycle_days_default), plus de table figée volaille.
function selectedCycle() {
    const opt = el('breeding_type').selectedOptions[0];
    return opt && opt.dataset.cycle ? parseInt(opt.dataset.cycle) : 42;
}

function runFilters() {
    const type = el('breeding_type').value || "";

    // Propage l'espèce / le type de production choisis (champs cachés).
    const sel = el('breeding_type').selectedOptions[0];
    const speciesSlug = sel?.dataset.speciesSlug || "";
    el('species_id_hidden').value = sel?.dataset.speciesId || "";
    el('production_type_id_hidden').value = sel?.dataset.ptId || "";

    // Filtrage souches
    document.querySelectorAll('.model-opt').forEach(opt => {
        const match = !type || opt.dataset.type === type;
        opt.style.display = match ? '' : 'none';
        opt.disabled = !match;
    });
    if (el('model_selector').selectedOptions[0]?.style.display === 'none') el('model_selector').value = "";

    // Filtrage bâtiments par espèce (strict, comme au lancement de lot) :
    // on n'autorise QUE les types de bâtiment compatibles avec l'espèce + 'mixte'.
    // Pas de repli permissif : on ne doit jamais pouvoir affecter un canard à
    // une bergerie. Si aucun bâtiment compatible n'existe, il faut d'abord en
    // créer un du bon type.
    const allowed = SPECIES_BUILDING_TYPES[speciesSlug] || null;
    const matches = (bType) => bType === 'mixte' || (allowed ? allowed.includes(bType) : (!type || bType === type));
    document.querySelectorAll('.building-opt').forEach(opt => {
        const compatible = !type || matches(opt.dataset.type);
        opt.style.display = compatible ? '' : 'none';
        opt.disabled = !compatible;
    });
    if (el('building_id').selectedOptions[0]?.disabled) el('building_id').value = "";

    // Filtrage protocoles
    document.querySelectorAll('.protocol-option').forEach(opt => {
        opt.style.display = (!type || opt.dataset.type === type) ? '' : 'none';
    });

    // Reproducteurs : afficher mâles/femelles
    const isRepro = type === 'reproducteur';
    el('repro_fields').classList.toggle('hidden', !isRepro);
    const qtyInput = el('planned_qty');
    if (isRepro) { qtyInput.readOnly = true; qtyInput.classList.add('bg-slate-200'); }
    else { qtyInput.readOnly = false; qtyInput.classList.remove('bg-slate-200'); }

    calculateAll();
    calculateDates();
}

function updateReproTotal() {
    const m = parseInt(el('qty_males')?.value) || 0;
    const f = parseInt(el('qty_females')?.value) || 0;
    el('planned_qty').value = m + f;

    // Ratio
    if (f > 0) {
        const ratio = (m / f * 100).toFixed(1);
        el('ratio_display').innerText = ratio + "%";
        const status = el('ratio_status');
        if (ratio >= {{ setting('elevage.mating_ratio_min', 8) }} && ratio <= {{ setting('elevage.mating_ratio_max', 12) }}) { status.innerText = "✅ Optimal"; status.className = "px-4 py-2 rounded-xl text-[9px] font-black uppercase italic tracking-widest bg-emerald-100 text-emerald-600"; }
        else { status.innerText = "⚠️ Hors norme"; status.className = "px-4 py-2 rounded-xl text-[9px] font-black uppercase italic tracking-widest bg-amber-100 text-amber-600"; }
    }
    calculateAll();
}

function calculateAll() {
    const type = el('breeding_type').value || "";
    const qty = parseInt(el('planned_qty')?.value) || 0;
    const bSelect = el('building_id');
    let remaining = 0, surface = 0, bName = "—";

    if (bSelect.value) {
        const opt = bSelect.options[bSelect.selectedIndex];
        remaining = parseInt(opt.dataset.remaining) || 0;
        surface = parseFloat(opt.dataset.surface) || 0;
        bName = opt.text.split('|')[0].trim();
    }

    // Densité
    const density = (qty > 0 && surface > 0) ? (qty / surface).toFixed(1) : 0;
    if (density > 0) { el('density_badge').classList.remove('hidden'); el('density_value').innerText = density; }
    else { el('density_badge').classList.add('hidden'); }

    // Résumé sidebar
    el('sum_type').innerText = type ? type.toUpperCase() : '—';
    el('sum_building').innerText = bName;
    el('sum_qty').innerText = qty.toLocaleString('fr-FR');
    el('sum_density').innerText = density > 0 ? density + ' S/m²' : '—';
    el('sum_cycle').innerText = type ? selectedCycle() + ' jours' : '—';

    // Validation capacité
    let error = "";
    if (bSelect.value && qty > remaining) error = "CAPACITÉ INSUFFISANTE — Max: " + remaining + " places";
    if (!type) error = "CHOISIR UN TYPE D'ÉLEVAGE";

    const alert = el('capacity_alert');
    const btn = el('submitBtn');
    if (error) {
        alert.classList.remove('hidden'); el('capacity_msg').innerText = error;
        if (btn) { btn.disabled = true; btn.className = "w-full bg-red-600 text-white font-black py-8 rounded-[2rem] cursor-not-allowed uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none"; btn.innerHTML = '<i class="fas fa-lock mr-2"></i> ' + error; }
    } else {
        alert.classList.add('hidden');
        if (btn) { btn.disabled = false; btn.className = "w-full bg-indigo-600 text-white font-black py-8 rounded-[2rem] hover:bg-indigo-700 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer"; btn.innerHTML = '<i class="fas fa-calendar-plus mr-2"></i> Enregistrer la planification'; }
    }
}

function calculateDates() {
    const type = el('breeding_type').value;
    const arrival = el('arrival_date').value;
    const panel = el('dates_panel');

    if (!type || !arrival) { panel.style.display = 'none'; return; }
    panel.style.display = '';

    const d = new Date(arrival);
    const cycle = selectedCycle();
    const fmt = dt => dt.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const add = (dt, days) => { const r = new Date(dt); r.setDate(r.getDate() + days); return r; };

    el('dt_order').innerText = fmt(add(d, -{{ setting('planning.order_lead_days', 56) }}));
    el('dt_arrival').innerText = fmt(d);
    el('dt_end').innerText = fmt(add(d, cycle));
    el('dt_end_label').innerText = CYCLE_LABELS[type] || 'Fin';
    el('dt_end_j').innerText = 'J+' + cycle;
    const voidDays = {{ setting('planning.void_sanitaire_days', 21) }};
    el('dt_void').innerText = fmt(add(d, cycle + 1)) + ' → ' + fmt(add(d, cycle + voidDays));
    el('dt_free').innerText = fmt(add(d, cycle + voidDays + 1));
}

window.addEventListener('DOMContentLoaded', () => { runFilters(); calculateDates(); });
</script>
</x-app-layout>
