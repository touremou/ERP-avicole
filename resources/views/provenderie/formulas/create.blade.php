<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-flask-vial text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">Nouvelle Formulation</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">Laboratoire — Création de Recette</p>
                </div>
            </div>
            <a href="{{ route('formulas.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> Annuler
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold" x-data="formulaBuilder()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic">Erreurs de validation</h3>
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight mt-2">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            @can('provenderie.C')
            <form action="{{ route('formulas.store') }}" method="POST" class="space-y-8" id="formula_form" @submit.prevent="submitForm">
                @csrf
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    {{-- 01. CONFIGURATION & NORMES --}}
                    <div class="space-y-6">
                        <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 space-y-6 text-left">
                            <h3 class="text-[10px] font-black uppercase text-blue-500 tracking-widest italic">01. Paramètres & Cibles</h3>
                            
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest ml-2">Secteur Avicole</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button type="button" @click="poultryType = 'Chair'; updateName()" 
                                        :class="poultryType === 'Chair' ? 'bg-slate-900 text-white shadow-lg' : 'bg-slate-100 text-slate-400'"
                                        class="py-3 rounded-xl text-[9px] font-black uppercase italic transition-all">
                                        <i class="fa-solid fa-feather mr-1"></i> Chair
                                    </button>
                                    <button type="button" @click="poultryType = 'Ponte'; updateName()" 
                                        :class="poultryType === 'Ponte' ? 'bg-emerald-600 text-white shadow-lg' : 'bg-slate-100 text-slate-400'"
                                        class="py-3 rounded-xl text-[9px] font-black uppercase italic transition-all">
                                        <i class="fa-solid fa-egg mr-1"></i> Ponte
                                    </button>
                                </div>
                                <input type="hidden" name="poultry_type" :value="poultryType">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">Nom</label>
                                <input type="text" name="name" x-model="formulaName" readonly
                                    class="w-full bg-blue-50 border-none rounded-2xl p-4 font-black text-blue-900 shadow-inner italic uppercase">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">Phase d'Élevage</label>
                                <select x-model="phase" @change="updateName()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                                    <template x-if="poultryType === 'Chair'">
                                        <optgroup label="Aliments Chair">
                                            <option value="Démarrage">Démarrage</option>
                                            <option value="Croissance">Croissance</option>
                                            <option value="Finition">Finition</option>
                                        </optgroup>
                                    </template>
                                    <template x-if="poultryType === 'Ponte'">
                                        <optgroup label="Aliments Ponte">
                                            <option value="Poussin">Démarrage (Poussin)</option>
                                            <option value="Poulette">Croissance (Poulette)</option>
                                            <option value="Ponte 1">Ponte 1 (Pic)</option>
                                            <option value="Ponte 2">Ponte 2 (Entretien)</option>
                                        </optgroup>
                                    </template>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">Référentiel Normé</label>
                                <select name="target_type" x-model="selectedNormId" @change="updateTargets()" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none">
                                    <option value="">-- CHOISIR UNE NORME --</option>
                                    @foreach($norms as $norm)
                                        <option value="{{ $norm->animal_type }}" 
                                                data-em="{{ $norm->target_em }}" 
                                                data-pb="{{ $norm->target_pb }}">
                                            {{ strtoupper($norm->name) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">Code</label>
                                    <input type="text" name="code" required placeholder="EX: CH-D01" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner text-center italic uppercase">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">Base (kg)</label>
                                    <input type="number" name="total_batch_weight" x-model.number="batchWeight" value="1000" class="w-full bg-slate-900 text-white border-none rounded-2xl p-4 font-black text-center shadow-lg italic">
                                </div>
                            </div>
                        </div>

                        {{-- DASHBOARD NUTRITIONNEL TEMPS RÉEL --}}
                        <div class="bg-slate-900 p-8 rounded-[3rem] shadow-2xl text-white space-y-6 relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-24 h-24 bg-blue-500/10 rounded-bl-full"></div>
                            <h4 class="text-[10px] font-black text-blue-400 uppercase tracking-widest mb-4 italic text-left">Dashboard Temps Réel</h4>
                            
                            <div class="space-y-2 text-left">
                                <div class="flex justify-between text-[9px] font-black uppercase italic">
                                    <span class="opacity-50">Énergie (EM)</span>
                                    <span><span x-text="Math.round(realEM)">0</span> / <span x-text="targetEM">0</span> <small>kcal</small></span>
                                </div>
                                <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-500 transition-all duration-500" :style="'width:' + Math.min((realEM / Math.max(targetEM, 1)) * 100, 100) + '%'"></div>
                                </div>
                            </div>

                            <div class="space-y-2 text-left">
                                <div class="flex justify-between text-[9px] font-black uppercase italic">
                                    <span class="opacity-50">Protéines (PB)</span>
                                    <span><span x-text="realPB.toFixed(1)">0</span> / <span x-text="targetPB">0</span> <small>%</small></span>
                                </div>
                                <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-emerald-500 transition-all duration-500" :style="'width:' + Math.min((realPB / Math.max(targetPB, 1)) * 100, 100) + '%'"></div>
                                </div>
                            </div>

                            <div class="pt-4 border-t border-slate-800 text-left">
                                <div class="flex justify-between items-end">
                                    <span class="text-[9px] uppercase opacity-50 italic">Coût Théorique</span>
                                    <span class="text-2xl font-black italic tracking-tighter"><span x-text="costPerKg.toLocaleString()">0</span> <small class="text-[10px]">GNF/kg</small></span>
                                </div>
                            </div>

                            {{-- INDICATEUR TOTAL % --}}
                            <div class="pt-4 border-t border-slate-800 text-left">
                                <div class="flex justify-between items-end">
                                    <span class="text-[9px] uppercase opacity-50 italic">Total Formule</span>
                                    <span class="text-2xl font-black italic tracking-tighter" :class="Math.abs(totalPercentage - 100) < 0.1 ? 'text-emerald-400' : 'text-red-400'" x-text="totalPercentage.toFixed(2) + '%'">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 02. COMPOSITION — FORMAT UNIFIÉ ingredients[].id + ingredients[].percentage --}}
                    <div class="lg:col-span-2">
                        <div class="bg-white p-8 rounded-[3.5rem] shadow-sm border border-slate-100 text-left flex flex-col h-full">
                            <div class="flex justify-between items-center mb-8 px-4">
                                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">02. Dosage des Ingrédients (% de la base)</h3>
                                <div class="flex gap-2">
                                    <span class="px-4 py-1 rounded-full text-[9px] font-black uppercase italic transition-colors"
                                          :class="Math.abs(totalPercentage - 100) < 0.1 ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-red-50 text-red-600 border border-red-200'"
                                          x-text="totalPercentage.toFixed(2) + '% / 100%'">
                                    </span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 flex-1 overflow-y-auto pr-2 custom-scrollbar max-h-[600px]">
                                @foreach($materials as $index => $m)
                                <div class="flex items-center gap-3 p-4 bg-slate-50 rounded-[2rem] hover:bg-white border-2 border-transparent hover:border-blue-100 transition-all group shadow-sm hover:shadow-md">
                                    {{-- Hidden : ID de la matière première --}}
                                    <input type="hidden" name="ingredients[{{ $index }}][id]" value="{{ $m->id }}">
                                    
                                    <div class="flex-1 text-left">
                                        <p class="text-[10px] font-black uppercase italic text-slate-700 leading-none truncate">{{ $m->name }}</p>
                                        <p class="text-[8px] text-slate-400 mt-1 uppercase font-bold">
                                            PB: {{ $m->protein_rate }}% | EM: {{ $m->energy_kcal }} | 
                                            <span class="text-blue-500">{{ number_format($m->unit_cost, 0) }} GNF/kg</span>
                                        </p>
                                    </div>
                                    <div class="w-24">
                                        <input type="number" step="0.01" min="0" max="100"
                                            name="ingredients[{{ $index }}][percentage]"
                                            data-cost="{{ $m->unit_cost }}" 
                                            data-pb="{{ $m->protein_rate }}"
                                            data-em="{{ $m->energy_kcal }}"
                                            placeholder="0.00"
                                            @input="recalculate()"
                                            class="pct-input w-full bg-white border-none rounded-xl p-3 font-black text-right text-blue-600 shadow-inner focus:ring-2 focus:ring-blue-500/20 italic">
                                    </div>
                                    <span class="text-[8px] text-slate-300 font-black italic">%</span>
                                </div>
                                @endforeach
                            </div>

                            <div class="mt-10 pt-6 border-t border-slate-50 flex justify-between items-center">
                                <p class="text-[9px] text-slate-400 italic font-medium">Le total des pourcentages doit être exactement 100%.</p>
                                <button type="submit" :disabled="Math.abs(totalPercentage - 100) > 0.1 || totalPercentage === 0"
                                    class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-blue-600 transition-all disabled:opacity-20 disabled:cursor-not-allowed active:scale-95">
                                    <i class="fa-solid fa-cloud-arrow-up mr-2 text-blue-400"></i> Enregistrer Recette
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            @else
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2">Accès Laboratoire Verrouillé</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">Seuls les profils de création (C) peuvent éditer de nouvelles recettes.</p>
                <a href="{{ route('formulas.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-500 transition-all">Retour</a>
            </div>
            @endcan
        </div>
    </div>

    <script>
        function formulaBuilder() {
            return {
                poultryType: 'Chair',
                phase: 'Démarrage',
                formulaName: 'CHAIR DÉMARRAGE',
                batchWeight: 1000,
                selectedNormId: '',
                targetEM: 0,
                targetPB: 0,
                totalPercentage: 0,
                realEM: 0,
                realPB: 0,
                costPerKg: 0,

                updateName() {
                    this.formulaName = this.poultryType.toUpperCase() + ' ' + this.phase.toUpperCase();
                },

                updateTargets() {
                    const select = this.$el.querySelector('select[name="target_type"]');
                    const opt = select?.options[select.selectedIndex];
                    this.targetEM = parseFloat(opt?.dataset?.em) || 0;
                    this.targetPB = parseFloat(opt?.dataset?.pb) || 0;
                },

                recalculate() {
                    const inputs = this.$el.querySelectorAll('.pct-input');
                    let totalPct = 0, totalCost = 0, totalEM = 0, totalPB = 0;

                    inputs.forEach(input => {
                        const pct = parseFloat(input.value) || 0;
                        const cost = parseFloat(input.dataset.cost) || 0;
                        const em = parseFloat(input.dataset.em) || 0;
                        const pb = parseFloat(input.dataset.pb) || 0;

                        totalPct += pct;
                        totalCost += (pct / 100) * cost;
                        totalEM += (pct / 100) * em;
                        totalPB += (pct / 100) * pb;
                    });

                    this.totalPercentage = totalPct;
                    this.realEM = totalEM;
                    this.realPB = totalPB;
                    this.costPerKg = Math.round(totalCost);
                },

                submitForm() {
                    if (Math.abs(this.totalPercentage - 100) > 0.1) {
                        alert('Le total des pourcentages doit être de 100%. Actuellement : ' + this.totalPercentage.toFixed(2) + '%');
                        return;
                    }
                    this.$el.querySelector('#formula_form').submit();
                }
            }
        }
    </script>
</x-app-layout>
