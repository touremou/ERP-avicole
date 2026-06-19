<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                {{ __("💉 Enregistrer une Intervention Sanitaire") }}
            </h2>
            <a href="{{ route('health.index') }}" class="text-[10px] font-black text-slate-400 hover:text-slate-900 uppercase tracking-widest transition italic no-underline">
                <i class="fa-solid fa-list-check mr-1 text-blue-500"></i> {{ __("Voir le registre") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            {{-- GESTION DES ERREURS --}}
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl animate-pulse text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic">{{ __("⚠️ Erreurs de saisie détectées") }}</h3>
                    <ul class="text-xs list-disc ml-5 opacity-90 font-black uppercase tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('health.store') }}" method="POST" class="bg-white p-10 rounded-[3rem] shadow-xl border border-slate-100 space-y-8 text-left">
                @csrf

                {{-- SECTION 01 : CONTEXTE ET LOT --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left">
                    {{-- CHOIX DU LOT --}}
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Lot à traiter") }} <span class="text-red-500">*</span></label>
                        <select name="batch_id" id="batch_select" onchange="showBatchType()" class="w-full p-5 bg-slate-50 border-none rounded-2xl font-black text-slate-700 shadow-inner focus:ring-4 focus:ring-blue-500/10 transition italic appearance-none cursor-pointer" required>
                            <option value="">{{ __("-- Sélectionner le lot --") }}</option>
                            @foreach($batches as $batch)
                                <option value="{{ $batch->id }}" 
                                    data-type="{{ strtoupper($batch->type) }}"
                                    {{ (old('batch_id', $selected_batch_id) == $batch->id) ? 'selected' : '' }}>
                                    {{ $batch->code }} ({{ $batch->building->name ?? 'N/A' }})
                                </option>
                            @endforeach
                        </select>
                        <div id="type_badge" class="hidden mt-2 ml-2">
                            <span class="px-3 py-1 bg-blue-100 text-blue-600 rounded-lg text-[8px] font-black uppercase italic" id="type_text"></span>
                        </div>
                    </div>

                    {{-- DATE DE L'ACTE --}}
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Date d'intervention") }} <span class="text-red-500">*</span></label>
                        <input type="date" name="intervention_date" id="intervention_date" value="{{ old('intervention_date', $prefill_date ?? date('Y-m-d')) }}"
                               oninput="checkExpiry()"
                               class="w-full p-5 bg-slate-50 border-none rounded-2xl font-black text-center shadow-inner focus:ring-4 focus:ring-blue-500/10 transition italic" required>
                    </div>
                </div>

                {{-- SECTION 02 : DÉTAILS DU PRODUIT --}}
                <div class="p-10 bg-slate-50 rounded-[3rem] border border-dashed border-slate-200 space-y-8 text-left italic">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-3 text-left">
                            <label class="text-[10px] font-black text-blue-600 uppercase ml-2 italic tracking-widest">{{ __("Type d'intervention") }}</label>
                            <select name="type" class="w-full p-5 bg-white rounded-2xl border-none shadow-sm font-black uppercase italic focus:ring-4 focus:ring-blue-500/10 transition appearance-none cursor-pointer" required>
                                @php $currentType = old('type', request('type', 'Vaccin')); @endphp
                                <option value="Vaccin" {{ $currentType == 'Vaccin' ? 'selected' : '' }}>{{ __("💉 Vaccin") }}</option>
                                <option value="Traitement" {{ $currentType == 'Traitement' ? 'selected' : '' }}>{{ __("💊 Traitement Médical") }}</option>
                                <option value="Vitamine" {{ $currentType == 'Vitamine' ? 'selected' : '' }}>{{ __("✨ Complément Vitamine") }}</option>
                                <option value="Désinfection" {{ $currentType == 'Désinfection' ? 'selected' : '' }}>{{ __("🧼 Désinfection / Vide") }}</option>
                            </select>
                        </div>
                        <div class="space-y-3 text-left">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Nom du produit / Acte") }}</label>
                            <input type="text" name="product_name" value="{{ old('product_name', $prefill_product) }}" 
                                   placeholder="{{ __("Ex: GUMBORO, ALVITYL...") }}"
                                   class="w-full p-5 bg-white rounded-2xl border-none shadow-sm font-black uppercase focus:ring-4 focus:ring-blue-500/10 transition italic text-slate-800" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest leading-none">{{ __("Numéro de Lot") }}</label>
                            <input type="text" name="batch_number" value="{{ old('batch_number') }}" placeholder="{{ __("FACULTATIF") }}"
                                   class="w-full p-4 bg-white rounded-2xl border-none shadow-sm font-black text-[10px] uppercase italic text-slate-500">
                        </div>
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest leading-none">{{ __("Date d'expiration") }}</label>
                            <input type="date" name="expiry_date" id="expiry_date" value="{{ old('expiry_date') }}"
                                   oninput="checkExpiry()"
                                   class="w-full p-4 bg-white rounded-2xl border-none shadow-sm font-black text-[10px] italic text-slate-500">
                            <p id="expiry_warning" class="hidden text-[9px] font-black text-red-600 uppercase italic ml-2 leading-tight">
                                ⛔ {{ __("Produit périmé : administration interdite") }}
                            </p>
                        </div>
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest leading-none">{{ __("Vétérinaire / Responsable") }}</label>
                            <input type="text" name="veterinary_name" value="{{ old('veterinary_name') }}" placeholder="{{ __("NOM DE L'AGENT") }}"
                                   class="w-full p-4 bg-white rounded-2xl border-none shadow-sm font-black text-[10px] uppercase italic text-slate-500">
                        </div>
                    </div>
                </div>

                {{-- SECTION 03 : ADMINISTRATION & FINANCES --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left">
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Mode d'administration") }}</label>
                        <select name="mode_administration" class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-[10px] uppercase shadow-inner italic transition focus:ring-4 focus:ring-blue-500/10 appearance-none cursor-pointer" required>
                            <option value="Eau de boisson" {{ old('mode_administration') == 'Eau de boisson' ? 'selected' : '' }}>{{ __("💧 Eau de boisson") }}</option>
                            <option value="Injection" {{ old('mode_administration') == 'Injection' ? 'selected' : '' }}>{{ __("💉 Injection IM/SC") }}</option>
                            <option value="Spray" {{ old('mode_administration', 'Eau de boisson') == 'Spray' ? 'selected' : '' }}>{{ __("💨 Spray / Nébulisation") }}</option>
                            <option value="Aliment" {{ old('mode_administration') == 'Aliment' ? 'selected' : '' }}>{{ __("🌾 Mélange Aliment") }}</option>
                            <option value="Oculaire" {{ old('mode_administration') == 'Oculaire' ? 'selected' : '' }}>{{ __("👁️ Goutte oculaire") }}</option>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-emerald-600 uppercase ml-2 italic tracking-widest">{{ __("Coût de l'opération (GNF)") }}</label>
                        <div class="relative">
                            <input type="number" name="cost" value="{{ old('cost', 0) }}" min="0" step="0.01"
                                   class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-2xl text-emerald-600 shadow-inner pl-16 focus:ring-4 focus:ring-emerald-500/10 transition italic">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-emerald-300 text-[10px] font-black">GNF</span>
                        </div>
                    </div>
                </div>

                {{-- OBSERVATIONS --}}
                <div class="space-y-3 text-left">
                    <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Rapport technique & Observations") }}</label>
                    <textarea name="observations" rows="4" placeholder="{{ __("REACTIONS POST-VACCINALES, ETAT GENERAL DES SUJETS...") }}" 
                              class="w-full p-8 bg-slate-50 rounded-[2.5rem] border-none shadow-inner font-bold text-slate-600 focus:bg-white transition italic outline-none">{{ old('observations') }}</textarea>
                </div>

                {{-- ACTIONS --}}
                <div class="flex flex-col md:flex-row gap-5 pt-8">
                    <a href="{{ route('health.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-7 rounded-[2.5rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-widest text-[10px] italic no-underline flex items-center justify-center">
                        {{ __("Annuler") }}
                    </a>
                    <button type="submit" id="health_submit" class="flex-[2] bg-slate-900 text-white font-black py-7 rounded-[2.5rem] hover:bg-blue-600 active:scale-95 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group cursor-pointer">
                        <i class="fas fa-check-circle mr-3 text-blue-400 group-hover:text-white transition-colors"></i> {{ __("Enregistrer l'intervention") }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => { showBatchType(); checkExpiry(); });

        // Garde-fou sanitaire côté client : le champ expiration ne peut pas être
        // antérieur à la date d'intervention (produit périmé). Bloque le bouton
        // de soumission et affiche une alerte. La validation serveur reste maître.
        function checkExpiry() {
            const interv = document.getElementById('intervention_date');
            const expiry = document.getElementById('expiry_date');
            const warning = document.getElementById('expiry_warning');
            const submitBtn = document.getElementById('health_submit');
            if (!interv || !expiry) return;

            // Le produit ne peut pas expirer avant le jour d'administration.
            expiry.min = interv.value || '';

            const expired = expiry.value && interv.value && expiry.value < interv.value;
            if (warning) warning.classList.toggle('hidden', !expired);
            expiry.classList.toggle('ring-2', expired);
            expiry.classList.toggle('ring-red-500', expired);
            if (submitBtn) {
                submitBtn.disabled = expired;
                submitBtn.style.opacity = expired ? '0.4' : '1';
            }
        }

        function showBatchType() {
            const select = document.getElementById('batch_select');
            const option = select.options[select.selectedIndex];
            const badge = document.getElementById('type_badge');
            const typeText = document.getElementById('type_text');

            if (option && option.value !== "") {
                badge.classList.remove('hidden');
                typeText.innerText = @json(__("Sujets : ")) + option.getAttribute('data-type');
            } else {
                badge.classList.add('hidden');
            }
        }
    </script>
</x-app-layout>