<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="text-left">
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🧪 {{ __("Architecte de Protocole") }}
                </h2>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-2 italic leading-none">{{ __("Modélisation des programmes sanitaires standards") }}</p>
            </div>
            <a href="{{ route('protocols.index') }}" class="group flex items-center px-6 py-3 bg-white border border-slate-200 text-slate-500 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-rose-50 hover:text-rose-600 transition-all shadow-sm no-underline italic">
                <i class="fa-solid fa-xmark mr-2 group-hover:rotate-90 transition-transform"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <form action="{{ route('protocols.store') }}" method="POST" id="protocolForm">
                @csrf

                {{-- SECTION A : CONFIGURATION GÉNÉRALE --}}
                <div class="bg-white p-10 rounded-[3rem] border border-slate-100 shadow-sm mb-8 relative overflow-hidden text-left">
                    <div class="absolute top-0 right-0 p-8 opacity-[0.05] text-slate-900"><i class="fa-solid fa-vial-circle-check text-8xl"></i></div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative z-10">
                        {{-- Nom du Modèle --}}
                        <div class="space-y-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase ml-2 italic leading-none tracking-widest">{{ __("Nom du Modèle") }}</label>
                            <input type="text" name="name" placeholder="{{ __('EX: CHAIR STANDARD 45J') }}" value="{{ old('name') }}" required
                                   class="w-full p-5 bg-slate-50 rounded-2xl border-none shadow-inner uppercase font-black text-slate-800 focus:ring-4 focus:ring-blue-500/10 transition-all italic outline-none">
                        </div>

                        {{-- Type d'Élevage --}}
                        <div class="space-y-3">
                            <label class="block text-[10px] font-black text-blue-500 uppercase ml-2 italic leading-none tracking-widest">{{ __("Type d'Élevage") }}</label>
                            <select name="type" id="type_selector" required onchange="filterStrains()" 
                                    class="w-full p-5 bg-slate-50 rounded-2xl border-none shadow-inner font-black text-blue-600 appearance-none italic uppercase cursor-pointer outline-none focus:ring-4 focus:ring-blue-500/10">
                                <option value="">{{ __("-- Sélectionner --") }}</option>
                                <option value="chair" {{ old('type') == 'chair' ? 'selected' : '' }}>🍗 {{ __("Poulet de chair") }}</option>
                                <option value="ponte" {{ old('type') == 'ponte' ? 'selected' : '' }}>🥚 {{ __("Pondeuses") }}</option>
                                <option value="poussiniere" {{ old('type') == 'poussiniere' ? 'selected' : '' }}>🐣 {{ __("Poussinière") }}</option>
                                <option value="reproducteur" {{ old('type') == 'reproducteur' ? 'selected' : '' }}>🐓 {{ __("Reproducteurs") }}</option>
                            </select>
                        </div>

                        {{-- Souche / Race --}}
                        <div class="space-y-3">
                            <label class="block text-[10px] font-black text-emerald-600 uppercase ml-2 italic leading-none tracking-widest">{{ __("Souche / Référentiel") }}</label>
                            <select name="strain" id="strain_selector" required
                                    class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none font-black text-emerald-600 shadow-inner appearance-none italic uppercase cursor-pointer">
                                <option value="">{{ __("-- Choisir le type --") }}</option>
                                @foreach($normModels as $norm)
                                    <option value="{{ $norm->model_name }}" 
                                            data-type="{{ strtolower($norm->batch_type) }}" 
                                            class="strain-opt" 
                                            style="display: none;">
                                        {{ $norm->model_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="md:col-span-3 space-y-3">
                            <label class="block text-[10px] font-black text-slate-400 uppercase ml-2 italic leading-none tracking-widest">{{ __("Description & Objectifs techniques") }}</label>
                            <textarea name="description" rows="2" placeholder="{{ __("Détails du programme (Ex: Protocol d'exportation, Norme Cobb500...)") }}"
                                      class="w-full p-6 bg-slate-50 rounded-[2rem] border-none shadow-inner font-bold text-slate-600 focus:bg-white transition italic uppercase text-[10px] outline-none">{{ old('description') }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- SECTION B : LES ÉTAPES DYNAMIQUES --}}
                <div class="bg-white p-10 rounded-[3rem] border border-slate-100 shadow-sm text-left italic">
                    <div class="flex justify-between items-center mb-10 border-b border-slate-50 pb-6">
                        <div>
                            <h3 class="text-[10px] font-black text-slate-800 uppercase tracking-[0.2em] leading-none italic">Chronologie des Interventions</h3>
                            <p class="text-[8px] text-slate-400 uppercase mt-2 italic font-black">Séquence temporelle (J1, J7, J14...)</p>
                        </div>
                        <button type="button" onclick="addStep()" class="px-8 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/10 tracking-[0.2em] border-none cursor-pointer">
                            <i class="fa-solid fa-calendar-plus mr-2 text-blue-400"></i> Ajouter un jour
                        </button>
                    </div>

                    <div id="steps-container" class="space-y-6">
                        {{-- 💡 RÉCUPÉRATION ROBUSTE DES ANCIENNES DONNÉES EN CAS D'ERREUR DE VALIDATION --}}
                        @php
                            $oldSteps = old('steps', isset($protocol) ? $protocol->steps->toArray() : [
                                ['day_number' => 1, 'action_name' => '', 'type' => 'Vaccin', 'method' => 'Eau de boisson'] // Valeur par défaut
                            ]);
                        @endphp

                        @foreach($oldSteps as $index => $step)
                            <div class="step-row flex flex-wrap lg:flex-nowrap gap-5 items-end bg-slate-50 p-8 rounded-[3rem] border border-dashed border-slate-200 group transition-all hover:border-blue-300">
                                <div class="w-24 shrink-0">
                                    <p class="text-[8px] font-black uppercase text-slate-400 mb-2 ml-2 leading-none italic">Jour</p>
                                    <div class="relative">
                                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-sm font-black italic">J</span>
                                        <input type="number" name="steps[{{ $index }}][day_number]" value="{{ $step['day_number'] ?? '' }}" min="0" required class="w-full pl-8 p-4 bg-white rounded-2xl border-none font-black text-blue-600 text-xl shadow-sm italic outline-none">
                                    </div>
                                </div>
                                <div class="flex-1 min-w-[250px]">
                                    <p class="text-[8px] font-black uppercase text-slate-400 mb-2 ml-2 leading-none italic">Action ou Produit spécifique</p>
                                    <input type="text" name="steps[{{ $index }}][action_name]" value="{{ $step['action_name'] ?? '' }}" placeholder="EX: VACCINATION HB1" required class="w-full bg-white rounded-2xl border-none font-black text-slate-800 uppercase shadow-sm p-4 italic text-sm outline-none focus:ring-2 focus:ring-blue-500/10">
                                </div>
                                <div class="w-52 shrink-0">
                                    <p class="text-[8px] font-black uppercase text-slate-400 mb-2 ml-2 leading-none italic">Catégorie</p>
                                    <select name="steps[{{ $index }}][type]" class="w-full bg-white border-none rounded-2xl text-[10px] font-black uppercase italic shadow-sm p-4 appearance-none cursor-pointer outline-none">
                                        <option value="Vaccin" {{ ($step['type'] ?? '') == 'Vaccin' ? 'selected' : '' }}>💉 Vaccin</option>
                                        <option value="Vitamine" {{ ($step['type'] ?? '') == 'Vitamine' ? 'selected' : '' }}>✨ Vitamine</option>
                                        <option value="Traitement" {{ ($step['type'] ?? '') == 'Traitement' ? 'selected' : '' }}>💊 Traitement</option>
                                        <option value="Désinfection" {{ ($step['type'] ?? '') == 'Désinfection' ? 'selected' : '' }}>🧼 Hygiène</option>
                                    </select>
                                </div>
                                <div class="w-52 shrink-0">
                                    <p class="text-[8px] font-black uppercase text-slate-400 mb-2 ml-2 leading-none italic">Méthode</p>
                                    <select name="steps[{{ $index }}][method]" class="w-full bg-white border-none rounded-2xl text-[10px] font-black uppercase italic shadow-sm p-4 appearance-none cursor-pointer outline-none">
                                        <option value="Eau de boisson" {{ ($step['method'] ?? '') == 'Eau de boisson' ? 'selected' : '' }}>💧 Eau de boisson</option>
                                        <option value="Injection" {{ ($step['method'] ?? '') == 'Injection' ? 'selected' : '' }}>💉 Injection</option>
                                        <option value="Spray" {{ ($step['method'] ?? '') == 'Spray' ? 'selected' : '' }}>💨 Spray</option>
                                        <option value="Aliment" {{ ($step['method'] ?? '') == 'Aliment' ? 'selected' : '' }}>🌾 Aliment</option>
                                        <option value="Oculaire" {{ ($step['method'] ?? '') == 'Oculaire' ? 'selected' : '' }}>👁️ Goutte</option>
                                    </select>
                                </div>
                                <div class="opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity pb-3">
                                    <button type="button" onclick="this.closest('.step-row').remove()" class="w-10 h-10 bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-500 hover:text-white transition-all border-none cursor-pointer"><i class="fa-solid fa-trash-can text-xs"></i></button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- ACTIONS --}}
                    <div class="mt-16 flex flex-col md:flex-row gap-6">
                        <a href="{{ route('protocols.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-7 rounded-[2.5rem] shadow-sm hover:bg-slate-50 transition-all text-center uppercase tracking-[0.2em] text-[10px] italic flex items-center justify-center no-underline">
                           Abandonner
                        </a>
                        <button type="submit" class="flex-[3] bg-slate-900 text-white py-7 rounded-[2.5rem] font-black uppercase italic tracking-[0.3em] text-[11px] hover:bg-emerald-600 transition-all shadow-2xl transform active:scale-95 border-none cursor-pointer">
                            <i class="fa-solid fa-vial-circle-check mr-3 text-emerald-400"></i> Finaliser et Sauvegarder l'Architecture
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- FILTRAGE DYNAMIQUE DES SOUCHES ---
        function filterStrains() {
            const selectedType = document.getElementById('type_selector').value.toLowerCase();
            const strainSelector = document.getElementById('strain_selector');
            const options = strainSelector.querySelectorAll('.strain-opt');
            
            strainSelector.value = ""; 
            
            options.forEach(opt => {
                const optType = opt.getAttribute('data-type').toLowerCase();
                if (optType === selectedType || selectedType === "") {
                    opt.style.display = "block";
                } else {
                    opt.style.display = "none";
                }
            });
        }

        // --- GÉNÉRATEUR D'ÉTAPES ---
        // 💡 CORRECTION : Le compteur s'initialise à la taille du tableau actuel
        let stepCount = {{ count(old('steps', isset($protocol) ? $protocol->steps : [1])) }};
        function addStep() {
            const container = document.getElementById('steps-container');
            const newRow = document.createElement('div');
            newRow.className = "step-row flex flex-wrap lg:flex-nowrap gap-5 items-end bg-slate-50 p-8 rounded-[3rem] border border-dashed border-slate-200 group transition-all hover:border-blue-300 animate-fadeIn";
            
            newRow.innerHTML = `
                <div class="w-24 shrink-0">
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-sm font-black italic">J</span>
                        <input type="number" name="steps[${stepCount}][day_number]" required min="0" class="w-full pl-8 p-4 bg-white rounded-2xl border-none font-black text-blue-600 text-xl shadow-sm italic outline-none">
                    </div>
                </div>
                <div class="flex-1 min-w-[250px]">
                    <input type="text" name="steps[${stepCount}][action_name]" placeholder="PRODUIT OU ACTE" required class="w-full bg-white rounded-2xl border-none font-black text-slate-800 uppercase shadow-sm p-4 italic text-sm outline-none">
                </div>
                <div class="w-52 shrink-0">
                    <select name="steps[${stepCount}][type]" class="w-full bg-white border-none rounded-2xl text-[10px] font-black uppercase italic shadow-sm p-4 appearance-none cursor-pointer outline-none">
                        <option value="Vaccin">💉 Vaccin</option>
                        <option value="Vitamine">✨ Vitamine</option>
                        <option value="Traitement">💊 Traitement</option>
                        <option value="Désinfection">🧼 Hygiène</option>
                    </select>
                </div>
                <div class="w-52 shrink-0">
                    <select name="steps[${stepCount}][method]" class="w-full bg-white border-none rounded-2xl text-[10px] font-black uppercase italic shadow-sm p-4 appearance-none cursor-pointer outline-none">
                        <option value="Eau de boisson">💧 Eau de boisson</option>
                        <option value="Injection">💉 Injection</option>
                        <option value="Spray">💨 Spray</option>
                        <option value="Aliment">🌾 Aliment</option>
                        <option value="Oculaire">👁️ Goutte</option>
                    </select>
                </div>
                <div class="opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity pb-3 text-right">
                    <button type="button" onclick="this.closest('.step-row').remove()" class="w-10 h-10 bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-500 hover:text-white transition-all border-none cursor-pointer"><i class="fa-solid fa-trash-can text-xs"></i></button>
                </div>
            `;
            container.appendChild(newRow);
            stepCount++;
        }

        document.addEventListener('DOMContentLoaded', filterStrains);
    </script>

    <style>
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeIn { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        
        select { 
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23cbd5e1'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); 
            background-position: right 1.25rem center; 
            background-repeat: no-repeat; 
            background-size: 0.8em; 
        }
    </style>
</x-app-layout>