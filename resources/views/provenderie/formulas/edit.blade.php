<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Édition') . ' : ' . $formula->name" :subtitle="__('Optimisation de la recette • Labo')" icon="fa-pen-nib" accent="amber" :back="route('formulas.show', $formula->id)" />
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            
            {{-- PROTECTION PERMISSION M (MODIFICATION) --}}
            @can('provenderie.M')
            <form action="{{ route('formulas.update', $formula->id) }}" method="POST" id="formulaForm">
                @csrf @method('PUT')

                <div class="space-y-8">
                    {{-- 1. INFOS GÉNÉRALES --}}
                    <div class="bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-sm grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest leading-none">{{ __("Nom de la Formule") }}</label>
                            <input type="text" name="name" value="{{ $formula->name }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black uppercase text-slate-800 shadow-inner italic focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest leading-none">{{ __("Espèce / Type de production") }}</label>
                            <select id="pt_selector_edit" onchange="onPtChangeEdit()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-blue-600 shadow-inner italic appearance-none cursor-pointer">
                                <option value="">{{ __("-- Choisir --") }}</option>
                                @foreach($productionTypes->groupBy(fn($pt) => $pt->species->name_fr ?? 'Autres') as $speciesLabel => $types)
                                    <optgroup label="{{ strtoupper($speciesLabel) }}">
                                        @foreach($types as $pt)
                                            <option value="{{ $pt->id }}" data-slug="{{ $pt->slug }}" data-species-id="{{ $pt->species_id }}"
                                                    @selected($formula->production_type_id == $pt->id)>
                                                {{ $pt->species->icon ?? '' }} {{ $pt->name_fr }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <input type="hidden" name="species_id" id="species_id_edit" value="{{ $formula->species_id }}">
                            <input type="hidden" name="production_type_id" id="production_type_id_edit" value="{{ $formula->production_type_id }}">
                            <input type="hidden" name="target_type" id="target_type_edit" value="{{ $formula->target_type }}">
                        </div>
                    </div>

                    {{-- 2. COMPOSITION DYNAMIQUE --}}
                    <div class="bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-sm">
                        <div class="flex justify-between items-center mb-8 px-4">
                            <h3 class="text-xs font-black uppercase text-slate-800 italic tracking-tighter leading-none">{{ __("Mélange des composants") }}</h3>
                            <button type="button" onclick="addRow()" class="text-[9px] bg-blue-600 text-white px-4 py-2 rounded-xl shadow-lg shadow-blue-200 hover:bg-slate-900 transition-all uppercase font-black italic">
                                <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                            </button>
                        </div>

                        <div id="ingredients-container" class="space-y-4">
                            @foreach($formula->items as $index => $item)
                            <div class="ingredient-row flex flex-col md:flex-row items-center gap-4 p-4 bg-slate-50 rounded-[2rem] border border-slate-100 relative group transition-all hover:bg-white hover:shadow-md">
                                <div class="flex-1 w-full text-left">
                                    <label class="block text-[8px] font-black text-slate-400 uppercase mb-1 ml-2 italic leading-none">{{ __("Matière Première") }}</label>
                                    <select name="ingredients[{{ $index }}][id]" required class="w-full bg-white border-none rounded-xl p-3 text-xs font-black text-slate-800 shadow-sm italic appearance-none">
                                        @foreach($rawMaterials as $rm)
                                            <option value="{{ $rm->id }}" @selected($item->raw_material_id == $rm->id)>{{ strtoupper($rm->name) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="w-full md:w-32 text-left">
                                    <label class="block text-[8px] font-black text-slate-400 uppercase mb-1 ml-2 italic text-center leading-none">{{ __("Part (%)") }}</label>
                                    <input type="number" step="0.01" name="ingredients[{{ $index }}][percentage]" value="{{ $item->percentage }}" oninput="calculateTotal()" required class="percentage-input w-full bg-white border-none rounded-xl p-3 text-center font-black text-blue-600 shadow-sm italic">
                                </div>
                                <button type="button" onclick="removeRow(this)" class="mt-4 md:mt-0 p-3 text-red-300 hover:text-red-500 transition-colors">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                            @endforeach
                        </div>

                        {{-- TOTAL CHECKER --}}
                        <div class="mt-8 p-6 rounded-[2.5rem] bg-slate-900 text-white flex justify-between items-center shadow-2xl relative overflow-hidden">
                            <div class="absolute right-0 top-0 w-24 h-24 bg-white/5 rounded-bl-full"></div>
                            <div class="text-left relative z-10">
                                <p class="text-[9px] font-black text-blue-400 uppercase italic leading-none mb-1">{{ __("Équilibre de la Formule") }}</p>
                                <p class="text-[8px] text-slate-400 italic font-bold leading-none">{{ __("Le dosage cumulé doit être égal à 100%") }}</p>
                            </div>
                            <div class="text-right relative z-10">
                                <span id="total-display" class="text-3xl font-black italic tracking-tighter transition-all">0%</span>
                            </div>
                        </div>
                    </div>

                    {{-- BOUTON ENREGISTRER --}}
                    <div class="flex flex-col items-center gap-4">
                        <button type="submit" id="submitBtn" disabled class="w-full bg-slate-900 text-white px-16 py-6 rounded-[2.5rem] text-xs font-black uppercase italic tracking-[0.2em] shadow-2xl hover:bg-emerald-600 transition-all disabled:opacity-20 disabled:cursor-not-allowed active:scale-95">
                            {{ __("Mettre à jour la Bibliothèque") }}
                        </button>
                        <p id="alert-msg" class="text-[9px] text-red-500 uppercase font-black italic animate-pulse">{{ __("L'équilibre à 100% n'est pas atteint") }}</p>
                    </div>
                </div>
            </form>
            @else
            {{-- VUE RESTREINTE POUR L, C, S --}}
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2">{{ __("Accès Restreint") }}</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("La permission") }} <span class="text-orange-500">provenderie.M</span> {{ __("(Modifier) est requise pour modifier cette recette.") }}</p>
                <a href="{{ route('formulas.show', $formula->id) }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline">{{ __("Retour aux Détails") }}</a>
            </div>
            @endcan
        </div>
    </div>

    <script>
        function el(id) { return document.getElementById(id); }
        let rowCount = {{ $formula->items->count() }};

        // Espèce / type de production → met à jour species_id, production_type_id
        // et target_type (slug). Sans sélection, on conserve les valeurs existantes.
        function onPtChangeEdit() {
            const s = el('pt_selector_edit'); const o = s.options[s.selectedIndex];
            el('species_id_edit').value = o.dataset.speciesId || '';
            el('production_type_id_edit').value = s.value || '';
            if (o.dataset.slug) el('target_type_edit').value = o.dataset.slug;
        }

        function addRow() {
            const container = el('ingredients-container');
            const row = document.createElement('div');
            row.className = 'ingredient-row flex flex-col md:flex-row items-center gap-4 p-4 bg-slate-50 rounded-[2rem] border border-slate-100 relative group animate-in slide-in-from-top duration-300 transition-all hover:bg-white hover:shadow-md';
            row.innerHTML = `
                <div class="flex-1 w-full text-left">
                    <label class="block text-[8px] font-black text-slate-400 uppercase mb-1 ml-2 italic leading-none">{{ __("Matière Première") }}</label>
                    <select name="ingredients[${rowCount}][id]" required class="w-full bg-white border-none rounded-xl p-3 text-xs font-black text-slate-800 shadow-sm italic appearance-none">
                        @foreach($rawMaterials as $rm)
                            <option value="{{ $rm->id }}">{{ strtoupper($rm->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full md:w-32 text-left">
                    <label class="block text-[8px] font-black text-slate-400 uppercase mb-1 ml-2 italic text-center leading-none">{{ __("Part (%)") }}</label>
                    <input type="number" step="0.01" name="ingredients[${rowCount}][percentage]" placeholder="0" oninput="calculateTotal()" required class="percentage-input w-full bg-white border-none rounded-xl p-3 text-center font-black text-blue-600 shadow-sm italic">
                </div>
                <button type="button" onclick="removeRow(this)" class="p-3 text-red-300 hover:text-red-500"><i class="fa-solid fa-trash-can"></i></button>
            `;
            container.appendChild(row);
            rowCount++;
            calculateTotal();
        }

        function removeRow(btn) {
            btn.closest('.ingredient-row').remove();
            calculateTotal();
        }

        function calculateTotal() {
            let total = 0;
            document.querySelectorAll('.percentage-input').forEach(input => {
                total += parseFloat(input.value) || 0;
            });

            const display = el('total-display');
            const btn = el('submitBtn');
            const msg = el('alert-msg');
            
            display.innerText = total.toFixed(2) + '%';
            
            // Tolérance pour erreurs d'arrondi
            if (Math.abs(total - 100) < 0.01) {
                display.classList.remove('text-red-500');
                display.classList.add('text-emerald-500');
                if(btn) btn.disabled = false;
                if(msg) msg.classList.add('hidden');
            } else {
                display.classList.remove('text-emerald-500');
                display.classList.add('text-red-500');
                if(btn) btn.disabled = true;
                if(msg) msg.classList.remove('hidden');
            }
        }

        // Initialisation immédiate
        document.addEventListener('DOMContentLoaded', calculateTotal);
    </script>
</x-app-layout>