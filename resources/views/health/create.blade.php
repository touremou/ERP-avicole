<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                💉 Enregistrer une Intervention Sanitaire
            </h2>
            <a href="{{ route('health.index') }}" class="text-[10px] font-black text-slate-400 hover:text-slate-900 uppercase tracking-widest transition italic no-underline">
                <i class="fa-solid fa-list-check mr-1 text-blue-500"></i> Voir le registre
            </a>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            {{-- GESTION DES ERREURS --}}
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl animate-pulse text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic">⚠️ Erreurs de saisie détectées</h3>
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
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">Lot à traiter <span class="text-red-500">*</span></label>
                        <select name="batch_id" id="batch_select" onchange="showBatchType()" class="w-full p-5 bg-slate-50 border-none rounded-2xl font-black text-slate-700 shadow-inner focus:ring-4 focus:ring-blue-500/10 transition italic appearance-none cursor-pointer" required>
                            <option value="">-- Sélectionner le lot --</option>
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
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">Date d'intervention <span class="text-red-500">*</span></label>
                        <input type="date" name="intervention_date" value="{{ old('intervention_date', $prefill_date ?? date('Y-m-d')) }}" 
                               class="w-full p-5 bg-slate-50 border-none rounded-2xl font-black text-center shadow-inner focus:ring-4 focus:ring-blue-500/10 transition italic" required>
                    </div>
                </div>

                {{-- SECTION 02 : DÉTAILS DU PRODUIT --}}
                <div class="p-10 bg-slate-50 rounded-[3rem] border border-dashed border-slate-200 space-y-8 text-left italic">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-3 text-left">
                            <label class="text-[10px] font-black text-blue-600 uppercase ml-2 italic tracking-widest">Type d'intervention</label>
                            <select name="type" class="w-full p-5 bg-white rounded-2xl border-none shadow-sm font-black uppercase italic focus:ring-4 focus:ring-blue-500/10 transition appearance-none cursor-pointer" required>
                                @php $currentType = old('type', request('type', 'Vaccin')); @endphp
                                <option value="Vaccin" {{ $currentType == 'Vaccin' ? 'selected' : '' }}>💉 Vaccin</option>
                                <option value="Traitement" {{ $currentType == 'Traitement' ? 'selected' : '' }}>💊 Traitement Médical</option>
                                <option value="Vitamine" {{ $currentType == 'Vitamine' ? 'selected' : '' }}>✨ Complément Vitamine</option>
                                <option value="Désinfection" {{ $currentType == 'Désinfection' ? 'selected' : '' }}>🧼 Désinfection / Vide</option>
                            </select>
                        </div>
                        <div class="space-y-3 text-left">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">Nom du produit / Acte</label>
                            <input type="text" name="product_name" value="{{ old('product_name', $prefill_product) }}" 
                                   placeholder="Ex: GUMBORO, ALVITYL..."
                                   class="w-full p-5 bg-white rounded-2xl border-none shadow-sm font-black uppercase focus:ring-4 focus:ring-blue-500/10 transition italic text-slate-800" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest leading-none">Numéro de Lot</label>
                            <input type="text" name="batch_number" value="{{ old('batch_number') }}" placeholder="FACULTATIF"
                                   class="w-full p-4 bg-white rounded-2xl border-none shadow-sm font-black text-[10px] uppercase italic text-slate-500">
                        </div>
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest leading-none">Date d'expiration</label>
                            <input type="date" name="expiry_date" value="{{ old('expiry_date') }}"
                                   class="w-full p-4 bg-white rounded-2xl border-none shadow-sm font-black text-[10px] italic text-slate-500">
                        </div>
                        <div class="space-y-3">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest leading-none">Vétérinaire / Responsable</label>
                            <input type="text" name="veterinary_name" value="{{ old('veterinary_name') }}" placeholder="NOM DE L'AGENT"
                                   class="w-full p-4 bg-white rounded-2xl border-none shadow-sm font-black text-[10px] uppercase italic text-slate-500">
                        </div>
                    </div>
                </div>

                {{-- SECTION 03 : ADMINISTRATION & FINANCES --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left">
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">Mode d'administration</label>
                        <select name="mode_administration" class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-[10px] uppercase shadow-inner italic transition focus:ring-4 focus:ring-blue-500/10 appearance-none cursor-pointer" required>
                            <option value="Eau de boisson" {{ old('mode_administration') == 'Eau de boisson' ? 'selected' : '' }}>💧 Eau de boisson</option>
                            <option value="Injection" {{ old('mode_administration') == 'Injection' ? 'selected' : '' }}>💉 Injection IM/SC</option>
                            <option value="Spray" {{ old('mode_administration', 'Eau de boisson') == 'Spray' ? 'selected' : '' }}>💨 Spray / Nébulisation</option>
                            <option value="Aliment" {{ old('mode_administration') == 'Aliment' ? 'selected' : '' }}>🌾 Mélange Aliment</option>
                            <option value="Oculaire" {{ old('mode_administration') == 'Oculaire' ? 'selected' : '' }}>👁️ Goutte oculaire</option>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-emerald-600 uppercase ml-2 italic tracking-widest">Coût de l'opération (GNF)</label>
                        <div class="relative">
                            <input type="number" name="cost" value="{{ old('cost', 0) }}" min="0" step="0.01"
                                   class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-2xl text-emerald-600 shadow-inner pl-16 focus:ring-4 focus:ring-emerald-500/10 transition italic">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-emerald-300 text-[10px] font-black">GNF</span>
                        </div>
                    </div>
                </div>

                {{-- OBSERVATIONS --}}
                <div class="space-y-3 text-left">
                    <label class="text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">Rapport technique & Observations</label>
                    <textarea name="observations" rows="4" placeholder="REACTIONS POST-VACCINALES, ETAT GENERAL DES SUJETS..." 
                              class="w-full p-8 bg-slate-50 rounded-[2.5rem] border-none shadow-inner font-bold text-slate-600 focus:bg-white transition italic outline-none">{{ old('observations') }}</textarea>
                </div>

                {{-- ACTIONS --}}
                <div class="flex flex-col md:flex-row gap-5 pt-8">
                    <a href="{{ url()->previous() }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-7 rounded-[2.5rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-widest text-[10px] italic no-underline flex items-center justify-center">
                        Annuler
                    </a>
                    <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-7 rounded-[2.5rem] hover:bg-blue-600 active:scale-95 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group cursor-pointer">
                        <i class="fas fa-check-circle mr-3 text-blue-400 group-hover:text-white transition-colors"></i> Enregistrer l'intervention
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', showBatchType);

        function showBatchType() {
            const select = document.getElementById('batch_select');
            const option = select.options[select.selectedIndex];
            const badge = document.getElementById('type_badge');
            const typeText = document.getElementById('type_text');

            if (option && option.value !== "") {
                badge.classList.remove('hidden');
                typeText.innerText = "Sujets : " + option.getAttribute('data-type');
            } else {
                badge.classList.add('hidden');
            }
        }
    </script>
</x-app-layout>