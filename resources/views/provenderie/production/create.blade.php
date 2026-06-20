<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-emerald-500 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-gears text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Lancer Production") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Provenderie • Ordre de Fabrication") }}</p>
                </div>
            </div>
            <a href="{{ route('production.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            
            {{-- VÉRIFICATION PERMISSION CRÉATION (C) --}}
            @can('provenderie.C')
            <div class="bg-white p-10 rounded-[3.5rem] shadow-2xl border border-slate-100 text-left relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-50 rounded-bl-full opacity-50 -mr-10 -mt-10"></div>

                <h3 class="text-2xl font-black text-slate-900 uppercase italic tracking-tighter mb-8 leading-none relative">
                    {{ __("Paramètres du Lot") }}
                </h3>

                <form action="{{ route('production.store') }}" method="POST" class="space-y-8 relative" id="prodForm">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        
                        {{-- 01. CONFIGURATION DE LA LIGNE --}}
                        <div class="space-y-6">
                            <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 space-y-6 text-left">
                                <h3 class="text-[10px] font-black uppercase text-emerald-500 tracking-widest italic">{{ __("01. Configuration de la Ligne") }}</h3>

                                {{-- SÉLECTEUR DE FORMULE --}}
                                <div class="space-y-2">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest ml-2">{{ __("Recette à produire") }}</label>
                                    <select name="formula_id" id="formula_id" required onchange="checkStocks()"
                                        class="w-full bg-slate-50 border-none rounded-2xl p-5 font-black text-slate-800 shadow-inner appearance-none italic focus:ring-2 focus:ring-emerald-500/20">
                                        <option value="">{{ __("-- CHOISIR UNE RECETTE --") }}</option>
                                        @foreach($formulas as $f)
                                            <option value="{{ $f->id }}"
                                                data-items="{{ json_encode($f->items->map(fn($item) => ['percentage' => (float) $item->percentage, 'raw_material' => ['name' => $item->rawMaterial?->name ?? __('MP supprimée'), 'stock_qty' => (float) ($item->rawMaterial?->stock_qty ?? 0), 'unit_cost' => (float) ($item->rawMaterial?->unit_cost ?? 0)]])) }}">
                                                {{ strtoupper($f->name) }} ({{ $f->code }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- SÉLECTION MULTIPLE DES MACHINES --}}
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center ml-2">
                                        <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest">{{ __("Machines activées (Ligne)") }}</label>
                                        <span id="machine_count" class="text-[9px] bg-slate-100 px-2 py-0.5 rounded-lg text-slate-500 font-black italic">{{ __("0 sélectionnée") }}</span>
                                    </div>
                                    <div class="grid grid-cols-1 gap-2 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                                        @forelse($machines->where('status', '!=', 'Désactivé') as $m)
                                        <label class="relative flex items-center p-4 rounded-2xl bg-slate-50 border-2 border-transparent cursor-pointer hover:bg-slate-100 transition-all has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 group">
                                            <input type="checkbox" name="machine_ids[]" value="{{ $m->id }}" 
                                                class="hidden peer machine-checkbox" onchange="updateMachineCount()">
                                            <div class="flex flex-col text-left">
                                                <span class="text-[10px] font-black uppercase italic text-slate-800 peer-checked:text-emerald-700">
                                                    {{ $m->name }}
                                                    @if($m->status != 'Opérationnel')
                                                        <span class="text-[7px] text-amber-500 ml-1">({{ $m->status }})</span>
                                                    @endif
                                                </span>
                                                <span class="text-[8px] font-bold text-slate-400 uppercase tracking-tighter italic">{{ $m->type }} • {{ $m->capacity_per_hour }} kg/h</span>
                                            </div>
                                            <div class="ml-auto w-5 h-5 rounded-full border-2 border-slate-200 flex items-center justify-center group-hover:border-emerald-300 peer-checked:bg-emerald-500 peer-checked:border-emerald-500">
                                                <i class="fa-solid fa-check text-[10px] text-white"></i>
                                            </div>
                                        </label>
                                        @empty
                                            <div class="p-4 bg-red-50 rounded-2xl border border-red-100 text-center">
                                                <p class="text-[9px] text-red-600 font-black uppercase italic">⚠️ {{ __("Aucune machine opérationnelle") }}</p>
                                            </div>
                                        @endforelse
                                    </div>
                                    <p id="machine_error" class="text-[8px] text-red-500 italic hidden text-center uppercase font-black">⚠️ {{ __("Veuillez sélectionner au moins une machine.") }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- 02. QUANTITÉ & RESPONSABLE --}}
                        <div class="space-y-6">
                            <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 space-y-6 text-left">
                                <h3 class="text-[10px] font-black uppercase text-blue-500 tracking-widest italic">{{ __("02. Volume de Production") }}</h3>

                                <div class="grid grid-cols-1 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest ml-2">{{ __("Nb de sacs (50kg)") }}</label>
                                        <input type="number" name="nb_bags" id="nb_bags" value="20" min="1" oninput="checkStocks()"
                                            class="w-full bg-slate-900 text-white border-none rounded-3xl p-6 font-black text-center text-2xl shadow-lg italic focus:ring-4 focus:ring-emerald-500/20">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest ml-2">{{ __("Poids Total Estimé") }}</label>
                                        <div class="relative">
                                            <input type="number" id="total_weight_display" readonly value="1000"
                                                class="w-full bg-slate-50 border-none rounded-3xl p-6 font-black text-center text-2xl text-slate-400 italic">
                                            <span class="absolute right-6 top-1/2 -translate-y-1/2 text-xs text-slate-300 font-black italic">KG</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 space-y-6 text-left">
                                <h3 class="text-[10px] font-black uppercase text-amber-500 tracking-widest italic">{{ __("03. Responsabilité") }}</h3>

                                <div class="space-y-2">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest ml-2">{{ __("Responsable Production") }}</label>
                                    <div class="relative group">
                                        <select name="supervisor_id" required
                                            class="w-full bg-slate-50 border-none rounded-2xl p-5 font-black text-slate-800 shadow-inner appearance-none italic focus:ring-2 focus:ring-amber-500/20">
                                            <option value="">{{ __("-- CHOISIR LE RESPONSABLE --") }}</option>
                                            @foreach($employees as $e)
                                                <option value="{{ $e->id }}">{{ $e->first_name }} {{ $e->last_name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="absolute right-5 top-1/2 -translate-y-1/2 pointer-events-none text-slate-300">
                                            <i class="fa-solid fa-user-tie"></i>
                                        </div>
                                    </div>
                                    <p class="text-[8px] text-slate-400 italic px-2 font-black uppercase">{{ __("Ce responsable valide la conformité du mélange.") }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 🛡️ ANALYSE DES STOCKS --}}
                    <div id="stock_check_container" class="bg-slate-50 p-8 rounded-[3rem] border border-slate-100 transition-all text-left">
                        <div class="flex justify-between items-center mb-6 px-4">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase italic tracking-widest">{{ __("Analyse de Faisabilité (Silos MP)") }}</h4>
                            <span id="stock_status_badge" class="px-4 py-1 rounded-full text-[8px] font-black uppercase italic tracking-widest hidden animate-bounce">
                                --
                            </span>
                        </div>

                        <div id="material_needs" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <p class="text-[9px] text-slate-300 italic uppercase py-8 text-center w-full col-span-3">{{ __("Veuillez sélectionner une formule pour analyser les stocks...") }}</p>
                        </div>
                    </div>

                    <button type="submit" id="submit_btn" disabled class="w-full bg-slate-100 text-slate-300 font-black py-8 rounded-[2.5rem] uppercase tracking-[0.4em] text-sm italic transition-all cursor-not-allowed shadow-inner border-2 border-transparent">
                        <i class="fa-solid fa-lock mr-2"></i> {{ __("Sélectionner les paramètres") }}
                    </button>
                </form>
            </div>
            @else
            {{-- VUE RESTREINTE POUR L, M, S --}}
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic font-bold">
                <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas les droits (C) pour créer un ordre de production.") }}</p>
                <a href="{{ route('production.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-emerald-500 transition-all">{{ __("Retour au Journal") }}</a>
            </div>
            @endcan

        </div>
    </div>

    <script>
        function updateMachineCount() {
            const checkboxes = document.querySelectorAll('.machine-checkbox');
            const checkedCount = Array.from(checkboxes).filter(c => c.checked).length;
            const display = document.getElementById('machine_count');
            const error = document.getElementById('machine_error');
            
            if(display) {
                display.innerText = checkedCount > 1
                    ? `${checkedCount} ${@json(__('sélectionnées'))}`
                    : `${checkedCount} ${@json(__('sélectionnée'))}`;
                if(checkedCount > 0) {
                    error.classList.add('hidden');
                    display.classList.replace('text-slate-500', 'text-emerald-600');
                    display.classList.add('bg-emerald-50');
                } else {
                    display.classList.replace('text-emerald-600', 'text-slate-500');
                    display.classList.remove('bg-emerald-50');
                }
            }
            checkStocks();
        }

        function checkStocks() {
            const select = document.getElementById('formula_id');
            const bags = document.getElementById('nb_bags').value || 0;
            const totalWeight = bags * 50;
            const container = document.getElementById('material_needs');
            const submitBtn = document.getElementById('submit_btn');
            const badge = document.getElementById('stock_status_badge');
            
            const checkboxes = document.querySelectorAll('.machine-checkbox');
            const machineSelected = Array.from(checkboxes).some(c => c.checked);

            if(document.getElementById('total_weight_display')) {
                document.getElementById('total_weight_display').value = totalWeight;
            }

            if (!select || !select.value || totalWeight <= 0) {
                if(container) container.innerHTML = `<p class="text-[9px] text-slate-300 italic uppercase py-8 text-center w-full col-span-3">${@json(__('Veuillez choisir une formule valide...'))}</p>`;
                if(badge) badge.classList.add('hidden');
                if(submitBtn) submitBtn.disabled = true;
                return;
            }

            try {
                const items = JSON.parse(select.options[select.selectedIndex].dataset.items);
                let isPossible = true;
                let hasUnpricedMp = false;
                let html = '';

                items.forEach(item => {
                    const need = (item.percentage / 100) * totalWeight;
                    const stock = item.raw_material ? parseFloat(item.raw_material.stock_qty) : 0;
                    const unitCost = item.raw_material ? parseFloat(item.raw_material.unit_cost || 0) : 0;
                    const matName = item.raw_material ? item.raw_material.name : @json(__('Inconnu'));
                    const enough = stock >= need;
                    const priced = unitCost > 0;
                    if (!enough) isPossible = false;
                    if (!priced) hasUnpricedMp = true;

                    // Priorité rouge (stock) > orange (prix) > blanc (OK)
                    const cardBg  = !enough ? 'bg-red-50 border border-red-100'
                                 : !priced ? 'bg-orange-50 border border-orange-200'
                                 : 'bg-white shadow-sm';
                    const qtyColor = !enough ? 'text-red-600' : 'text-slate-800';
                    const statusColor = !enough ? 'text-red-400'
                                     : !priced ? 'text-orange-500'
                                     : 'text-emerald-500';
                    const statusText = !enough
                        ? @json(__('Manque')) + ': ' + (need - stock).toFixed(1) + 'kg'
                        : !priced
                            ? '<i class="fa-solid fa-triangle-exclamation"></i> ' + @json(__('Prix non renseigné'))
                            : '<i class="fa-solid fa-check"></i> ' + @json(__('Prêt'));

                    // Ligne de prix (visible uniquement si MP a un coût)
                    const priceRow = priced
                        ? `<p class="text-[7px] text-slate-400 italic mt-1">${unitCost.toLocaleString(undefined, {minimumFractionDigits: 0})} GNF/kg</p>`
                        : '';

                    html += `
                        <div class="p-5 rounded-[2rem] ${cardBg} transition-all text-left">
                            <p class="text-[9px] font-black text-slate-400 uppercase leading-none mb-3 tracking-tighter italic">${matName}</p>
                            <div class="flex justify-between items-end">
                                <div>
                                    <span class="text-sm font-black ${qtyColor} italic">${need.toLocaleString(undefined, {minimumFractionDigits: 1})} <small class="text-[9px]">kg</small></span>
                                    ${priceRow}
                                </div>
                                <span class="text-[8px] font-black uppercase ${statusColor} italic text-right leading-tight">${statusText}</span>
                            </div>
                        </div>
                    `;
                });

                if(container) container.innerHTML = html;

                // Avertissement consolidé sur les prix manquants
                if (hasUnpricedMp) {
                    container.insertAdjacentHTML('beforeend', `
                        <div class="col-span-3 mt-2 p-4 bg-orange-50 border border-orange-200 rounded-2xl flex items-start gap-3">
                            <i class="fa-solid fa-triangle-exclamation text-orange-500 mt-0.5"></i>
                            <p class="text-[9px] font-black text-orange-700 uppercase italic leading-snug">
                                ${@json(__('Une ou plusieurs MP n\'ont pas de prix renseigné. La clôture de cette production sera bloquée : renseignez le prix dans Provenderie > Matières Premières.'))}
                            </p>
                        </div>
                    `);
                }

                if (submitBtn && badge) {
                    if (isPossible && machineSelected) {
                        submitBtn.disabled = false;
                        submitBtn.className = "w-full bg-slate-900 text-white font-black py-8 rounded-[2.5rem] shadow-2xl uppercase tracking-[0.4em] text-sm italic hover:bg-emerald-600 border-2 border-emerald-400/20 transition-all active:scale-95 cursor-pointer";
                        submitBtn.innerHTML = hasUnpricedMp
                            ? '<i class="fa-solid fa-triangle-exclamation mr-2 text-orange-400"></i> ' + @json(__('Planifier (⚠ Prix MP manquants)'))
                            : '<i class="fa-solid fa-play-circle mr-2 text-emerald-400"></i> ' + @json(__('Lancer la Production'));
                        badge.className = hasUnpricedMp
                            ? "px-4 py-1 bg-orange-100 text-orange-600 rounded-full text-[8px] font-black uppercase italic tracking-widest block"
                            : "px-4 py-1 bg-emerald-100 text-emerald-600 rounded-full text-[8px] font-black uppercase italic tracking-widest block";
                        badge.innerText = hasUnpricedMp ? @json(__("Silos OK • Prix MP ⚠")) : @json(__("Ligne de Silos : OK"));
                    } else {
                        submitBtn.disabled = true;
                        submitBtn.className = "w-full bg-slate-100 text-slate-300 font-black py-8 rounded-[2.5rem] uppercase tracking-[0.4em] text-sm italic cursor-not-allowed shadow-inner border-2 border-transparent";

                        if (!machineSelected) {
                            submitBtn.innerHTML = '<i class="fa-solid fa-cog fa-spin mr-2"></i> ' + @json(__('Configurer la ligne machine'));
                            if (document.getElementById('machine_error')) document.getElementById('machine_error').classList.remove('hidden');
                        } else {
                            submitBtn.innerHTML = '<i class="fa-solid fa-lock mr-2"></i> ' + @json(__('Rupture de Stock MP'));
                        }

                        badge.className = "px-4 py-1 bg-red-100 text-red-600 rounded-full text-[8px] font-black uppercase italic tracking-widest block";
                        badge.innerText = !isPossible ? @json(__("Achat Requis")) : @json(__("Config Machine"));
                    }
                    badge.classList.remove('hidden');
                }
            } catch (e) {
                if(container) container.innerHTML = `<p class="text-red-500 text-[9px] col-span-3">${@json(__('Erreur de structure des données.'))}</p>`;
            }
        }

        window.onload = checkStocks;
    </script>
</x-app-layout>