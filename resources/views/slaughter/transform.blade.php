<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Transformation") }}</h2>
                <p class="text-[10px] font-black text-amber-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Fumage, Grillage, Marinade") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="transformForm()" x-cloak>

            {{-- 🔒 SÉCURITÉ : Vérification de la permission de Création --}}
            @can('abattoir.C')
                @if($errors->any())
                    <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('slaughter.transform.store') }}">
                    @csrf
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                        <div class="space-y-6">

                            <div class="grid grid-cols-2 gap-6">
                                {{-- PRODUIT SOURCE — lié au stock --}}
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Produit source") }} *</label>
                                    <select name="product_source" x-model="selectedSource" @change="onSourceChange()" required
                                        class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="">{{ __("Sélectionner...") }}</option>
                                        @foreach($finishedProducts as $fp)
                                            <option value="{{ $fp->product_name }}" data-stock="{{ (float) $fp->current_quantity_kg }}">
                                                {{ $fp->product_name }} ({{ number_format($fp->current_quantity_kg, 1) }} kg)
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type de transformation") }} *</label>
                                    <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="fume">{{ __("Fumage") }}</option>
                                        <option value="grille">{{ __("Grillage") }}</option>
                                        <option value="marine">{{ __("Marinade") }}</option>
                                        <option value="autre">{{ __("Autre") }}</option>
                                    </select>
                                </div>
                            </div>

                            {{-- INDICATEUR STOCK DISPONIBLE --}}
                            <div x-show="maxKg > 0" class="p-4 rounded-2xl flex items-center justify-between"
                                :class="inputKg > maxKg ? 'bg-red-50 border border-red-200' : 'bg-emerald-50 border border-emerald-200'">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid" :class="inputKg > maxKg ? 'fa-circle-xmark text-red-500' : 'fa-circle-check text-emerald-500'"></i>
                                    <span class="text-[9px] font-black uppercase" :class="inputKg > maxKg ? 'text-red-600' : 'text-emerald-600'">
                                        {{ __("Stock disponible") }} : <span x-text="maxKg.toFixed(1)"></span> kg
                                    </span>
                                </div>
                                <div x-show="inputKg > maxKg" class="text-[9px] font-black text-red-600">
                                    {{ __("Dépassement de") }} <span x-text="(inputKg - maxKg).toFixed(1)"></span> kg !
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-emerald-600 tracking-widest ml-2">{{ __("Poids entrant (kg)") }} *</label>
                                    <input type="number" name="input_kg" x-model.number="inputKg" step="0.1" min="0.1" :max="maxKg || 99999" required
                                        class="w-full border-2 rounded-2xl p-4 text-lg font-black outline-none text-center"
                                        :class="inputKg > maxKg && maxKg > 0 ? 'bg-red-50 border-red-300 text-red-600' : 'bg-emerald-50 border-emerald-200 text-emerald-600'">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-blue-600 tracking-widest ml-2">{{ __("Poids sortant (kg)") }}</label>
                                    <input type="number" name="output_kg" x-model.number="outputKg" step="0.1" min="0"
                                        class="w-full bg-blue-50 border-2 border-blue-200 rounded-2xl p-4 text-lg font-black text-blue-600 outline-none text-center">
                                    <p class="text-[8px] text-slate-400 ml-2">{{ __("Laisser à 0 si pas encore terminé") }}</p>
                                </div>
                            </div>

                            {{-- RENDEMENT TEMPS RÉEL --}}
                            <div class="p-4 bg-slate-50 rounded-2xl text-center" x-show="inputKg > 0 && outputKg > 0">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Rendement transformation") }}</p>
                                <p class="text-2xl font-black" :class="yieldPct >= setting('abattoir.yield_smoking', 65) ? 'text-emerald-600' : (yieldPct >= setting('abattoir.yield_carcass', 72) ? 'text-amber-600' : 'text-red-600')" x-text="yieldPct.toFixed(1) + '%'"></p>
                                <p class="text-[8px] text-slate-400">{{ __("Fumage") }} : {{ setting('abattoir.yield_smoking', 65) }}% {{ __("cible") }} | {{ __("Carcasse") }} : {{ setting('abattoir.yield_carcass', 72) }}% {{ __("cible") }}</p>
                            </div>

                            {{-- BILAN --}}
                            <div class="grid grid-cols-3 gap-3 p-4 bg-slate-900 rounded-2xl text-white text-center" x-show="inputKg > 0">
                                <div>
                                    <p class="text-[7px] font-black text-slate-500 uppercase">{{ __("Entrant") }}</p>
                                    <p class="text-sm font-black text-white" x-text="inputKg.toFixed(1) + ' kg'"></p>
                                </div>
                                <div>
                                    <p class="text-[7px] font-black text-slate-500 uppercase">{{ __("Sortant") }}</p>
                                    <p class="text-sm font-black text-emerald-400" x-text="outputKg > 0 ? outputKg.toFixed(1) + ' kg' : {{ Js::from(__('En cours')) }}"></p>
                                </div>
                                <div>
                                    <p class="text-[7px] font-black text-slate-500 uppercase">{{ __("Perte") }}</p>
                                    <p class="text-sm font-black" :class="outputKg > 0 ? 'text-amber-400' : 'text-slate-500'" x-text="outputKg > 0 ? (inputKg - outputKg).toFixed(1) + ' kg' : '—'"></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date production") }} *</label>
                                    <input type="date" name="production_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date de péremption") }}</label>
                                    <input type="date" name="expiry_date" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Coût production (charbon, épices...)") }}</label>
                                {{-- ⚙️ MIGRATE: Devise dynamique --}}
                                <input type="number" name="cost" min="0" placeholder="{{ setting('general.currency', 'GNF') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none text-right">
                            </div>
                            <textarea name="notes" rows="2" placeholder="{{ __('Notes...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none"></textarea>
                        </div>
                    </div>

                    <button type="submit" :disabled="inputKg > maxKg && maxKg > 0"
                        :class="(inputKg > maxKg && maxKg > 0) ? 'bg-slate-300 cursor-not-allowed' : 'bg-amber-500 hover:bg-amber-600 cursor-pointer'"
                        class="w-full text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none">
                        <i class="fa-solid fa-fire mr-2"></i>
                        <span x-text="(inputKg > maxKg && maxKg > 0) ? {{ Js::from(__('STOCK INSUFFISANT')) }} : {{ Js::from(__('Enregistrer la Transformation')) }}"></span>
                    </button>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas la permission d'enregistrer des transformations.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
    function transformForm() {
        const stocks = @json($finishedProducts->pluck('current_quantity_kg', 'product_name'));

        return {
            selectedSource: '', inputKg: 0, outputKg: 0, maxKg: 0,

            get yieldPct() { return this.inputKg > 0 && this.outputKg > 0 ? (this.outputKg / this.inputKg * 100) : 0; },

            onSourceChange() {
                this.maxKg = parseFloat(stocks[this.selectedSource]) || 0;
                this.inputKg = 0;
                this.outputKg = 0;
            },
        }
    }
    </script>
</x-app-layout>