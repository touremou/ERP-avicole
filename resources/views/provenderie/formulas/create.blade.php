<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Nouvelle Formulation')" :subtitle="__('Laboratoire — Création de Recette')" icon="fa-flask-vial" accent="amber" :back="route('formulas.index')" />
    </x-slot>

    <div class="py-12 italic font-bold" x-data="formulaBuilderData()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic">{{ __("Erreurs de validation") }}</h3>
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
                            <h3 class="text-[10px] font-black uppercase text-blue-500 tracking-widest italic">{{ __("01. Paramètres & Cibles") }}</h3>

                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase italic tracking-widest ml-2">{{ __("Espèce / Type de production *") }}</label>
                                <select id="pt_selector" @change="onPtChange($event)" required
                                        class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-blue-600 shadow-inner italic appearance-none cursor-pointer">
                                    <option value="">{{ __("-- Choisir --") }}</option>
                                    @foreach($productionTypes->groupBy(fn($pt) => $pt->species->name_fr ?? 'Autres') as $speciesLabel => $types)
                                        <optgroup label="{{ strtoupper($speciesLabel) }}">
                                            @foreach($types as $pt)
                                                <option value="{{ $pt->id }}"
                                                        data-slug="{{ $pt->slug }}"
                                                        data-species-id="{{ $pt->species_id }}"
                                                        data-label="{{ strtoupper($pt->name_fr) }}">
                                                    {{ $pt->species->icon ?? '' }} {{ $pt->name_fr }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <input type="hidden" name="species_id" :value="speciesId">
                                <input type="hidden" name="production_type_id" :value="productionTypeId">
                                {{-- Champ NON lié à Alpine : il est écrit par le
                                     sélecteur de type de production ET par celui du
                                     référentiel (le dernier choix l'emporte). --}}
                                <input type="hidden" name="target_type" id="target_type" value="{{ old('target_type') }}">
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">{{ __("Nom") }}</label>
                                <input type="text" name="name" x-model="formulaName" required
                                    class="w-full bg-blue-50 border-none rounded-2xl p-4 font-black text-blue-900 shadow-inner italic uppercase">
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">{{ __("Code") }}</label>
                                    <input type="text" name="code" required placeholder="{{ __("EX: CH-D01") }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner text-center italic uppercase">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2 italic tracking-widest">{{ __("Base (kg)") }}</label>
                                    <input type="number" name="total_batch_weight" x-model.number="batchWeight" value="1000" class="w-full bg-slate-900 text-white border-none rounded-2xl p-4 font-black text-center shadow-lg italic">
                                </div>
                            </div>
                        </div>

                        {{-- DASHBOARD NUTRITIONNEL TEMPS RÉEL — rendu et calcul partagés
                             avec l'écran d'édition (cf. partials/lab-dashboard et
                             lab-script). Cet écran suivait deux nutriments sur les six
                             que fixe le référentiel. --}}
                        @include('provenderie.formulas.partials.lab-dashboard', ['norms' => $norms])
                    </div>

                    {{-- 02. COMPOSITION — FORMAT UNIFIÉ ingredients[].id + ingredients[].percentage --}}
                    <div class="lg:col-span-2">
                        <div class="bg-white p-8 rounded-[3.5rem] shadow-sm border border-slate-100 text-left flex flex-col h-full">
                            <div class="flex justify-between items-center mb-8 px-4">
                                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("02. Dosage des Ingrédients (% de la base)") }}</h3>
                                <div class="flex gap-2">
                                    <span data-lab-status class="px-4 py-1 rounded-full text-[9px] font-black uppercase italic transition-colors bg-slate-50 border border-slate-200">0% / 100%</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 flex-1 overflow-y-auto pr-2 custom-scrollbar max-h-[600px]">
                                @foreach($materials as $index => $m)
                                <div data-lab-row @include('provenderie.formulas.partials.material-data', ['material' => $m])
                                     class="flex items-center gap-3 p-4 bg-slate-50 rounded-[2rem] hover:bg-white border-2 border-transparent hover:border-blue-100 transition-all group shadow-sm hover:shadow-md">
                                    {{-- Hidden : ID de la matière première --}}
                                    <input type="hidden" name="ingredients[{{ $index }}][id]" value="{{ $m->id }}">
                                    
                                    <div class="flex-1 text-left">
                                        <p class="text-[10px] font-black uppercase italic text-slate-700 leading-none truncate">{{ $m->name }}</p>
                                        <p class="text-[8px] text-slate-400 mt-1 uppercase font-bold">
                                            {{ __("PB") }}: {{ $m->protein_rate }}% | {{ __("EM") }}: {{ $m->energy_kcal }} |
                                            <span class="text-blue-500">{{ number_format($m->unit_cost, 0) }} {{ currency() }}/kg</span>
                                        </p>
                                    </div>
                                    <div class="w-24">
                                        <input type="number" step="0.01" min="0" max="100"
                                            name="ingredients[{{ $index }}][percentage]"
                                            value="{{ old('ingredients.'.$index.'.percentage') }}"
                                            placeholder="0.00" data-lab-share
                                            class="w-full bg-white border-none rounded-xl p-3 font-black text-right text-blue-600 shadow-inner focus:ring-2 focus:ring-blue-500/20 italic">
                                    </div>
                                    <span class="text-[8px] text-slate-300 font-black italic">%</span>
                                </div>
                                @endforeach
                            </div>

                            <div class="mt-10 pt-6 border-t border-slate-50 flex justify-between items-center">
                                <p class="text-[9px] text-slate-400 italic font-medium">{{ __("Le total des pourcentages doit être exactement 100%.") }}</p>
                                <button type="submit" data-lab-submit disabled
                                    class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-blue-600 transition-all disabled:opacity-20 disabled:cursor-not-allowed active:scale-95">
                                    <i class="fa-solid fa-cloud-arrow-up mr-2 text-blue-400"></i> {{ __("Enregistrer Recette") }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            @else
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2">{{ __("Accès Laboratoire Verrouillé") }}</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Seuls les profils de création (C) peuvent éditer de nouvelles recettes.") }}</p>
                <a href="{{ route('formulas.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-500 transition-all">{{ __("Retour") }}</a>
            </div>
            @endcan
        </div>
    </div>

    @include('provenderie.formulas.partials.lab-script')

    <script>
        // Composant défini comme fonction globale ordinaire, sans Alpine.data().
        // Alpine évalue x-data="formulaBuilderData()" comme expression JS au moment
        // où il traite le DOM — après que ce script inline ait déjà été exécuté
        // (les scripts inline s'exécutent avant les modules Vite qui sont différés).
        // Aucun problème de timing ni de course avec alpine:init.
        window.formulaBuilderData = function () {
            return {
                formulaName: '',
                speciesId: '',
                productionTypeId: '',
                ptSlug: '',
                batchWeight: 1000,

                onPtChange(e) {
                    const opt = e.target.options[e.target.selectedIndex];
                    this.speciesId = opt?.dataset?.speciesId || '';
                    this.productionTypeId = e.target.value || '';
                    this.ptSlug = opt?.dataset?.slug || '';
                    document.getElementById('target_type').value = this.selectedNorm() || this.ptSlug;
                    if (opt?.dataset?.label) this.formulaName = opt.dataset.label;
                },

                /** La norme choisie au tableau de bord, s'il y en a une. */
                selectedNorm() {
                    return document.querySelector('[data-lab-norm]')?.value || '';
                },

                submitForm() {
                    // Le verrou à 100 % vit dans FormulaLab (bouton désactivé) et,
                    // en dernier ressort, dans StoreFormulaRequest côté serveur.
                    document.getElementById('formula_form').submit();
                }
            };
        };

        // Le référentiel choisi devient le type cible enregistré : la fiche
        // retrouvera ainsi SA norme, au lieu de retomber sur le slug du type de
        // production quand les deux diffèrent.
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('formula_form');
            if (! form) return;

            window.FormulaLab.attach(form);

            const normSelect = form.querySelector('[data-lab-norm]');
            const targetInput = document.getElementById('target_type');

            if (normSelect && targetInput) {
                normSelect.addEventListener('change', function () {
                    if (normSelect.value) targetInput.value = normSelect.value;
                });
            }
        });
    </script>
</x-app-layout>
