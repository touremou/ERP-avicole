<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="text-left">
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    {{ __("Modifier l'acte :") }} {{ $health->product_name }}
                </h2>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-2 italic leading-none">
                    {{ __("Affecté au lot") }} • <span class="text-blue-500 font-black">{{ $health->batch->code }}</span>
                </p>
            </div>
            <x-back />
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 text-left">

            {{-- BLOC ERREURS --}}
            @if ($errors->any())
                <div class="bg-rose-600 text-white p-6 rounded-[2.5rem] mb-8 shadow-xl animate-pulse text-left">
                    <h3 class="font-black uppercase text-xs mb-2 italic">⚠️ {{ __("Erreur de validation détectée") }}</h3>
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight opacity-90">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            {{-- FORMULAIRE --}}
            <form action="{{ route('health.update', $health->id) }}" method="POST" class="space-y-8" id="healthEditForm">
                @csrf 
                @method('PUT')

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 text-left">
                    
                    {{-- 01. RÉFÉRENCES & CONTEXTE --}}
                    <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 space-y-8">
                        <h3 class="text-[10px] font-black uppercase text-blue-500 tracking-[0.2em] italic leading-none border-b border-slate-50 pb-4">
                            <i class="fas fa-syringe mr-2"></i> {{ __("01. Contexte de l'acte") }}
                        </h3>

                        <div class="space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest">{{ __("Lot concerné") }}</label>
                                <select name="batch_id" required class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic focus:ring-4 focus:ring-blue-500/10 transition appearance-none cursor-pointer">
                                    @foreach($batches as $b)
                                        <option value="{{ $b->id }}" {{ old('batch_id', $health->batch_id) == $b->id ? 'selected' : '' }}>
                                            {{ $b->code }} ({{ $b->building->name ?? 'N/A' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Type d'intervention") }}</label>
                                <select name="type" required class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-blue-600 shadow-inner italic appearance-none focus:ring-4 focus:ring-blue-500/10 transition cursor-pointer">
                                    @foreach(['Vaccin' => '💉 VACCIN', 'Traitement' => '💊 TRAITEMENT', 'Vitamine' => '🧪 VITAMINE', 'Désinfection' => '🧼 DÉSINFECTION'] as $val => $label)
                                        <option value="{{ $val }}" {{ old('type', $health->type) == $val ? 'selected' : '' }}>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Date de l'acte effectif") }}</label>
                                <input type="date" name="intervention_date" value="{{ old('intervention_date', $health->intervention_date->format('Y-m-d')) }}" required
                                       class="w-full p-5 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic focus:ring-4 focus:ring-blue-500/10 transition">
                            </div>
                        </div>
                    </div>

                    {{-- 02. PRODUIT & ADMINISTRATION --}}
                    <div class="lg:col-span-2 space-y-8">
                        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
                            <h3 class="text-[10px] font-black uppercase text-emerald-500 tracking-[0.2em] italic mb-10 leading-none border-b border-slate-50 pb-4">
                                <i class="fas fa-flask mr-2"></i> {{ __("02. Produit & Administration") }}
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-10 text-left">
                                <div class="md:col-span-2">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Nom complet du produit") }}</label>
                                    <input type="text" name="product_name" value="{{ old('product_name', $health->product_name) }}" required
                                           class="w-full p-6 bg-slate-50 rounded-[2rem] font-black text-2xl border-none text-slate-800 shadow-inner italic focus:ring-4 focus:ring-emerald-500/10 transition outline-none">
                                </div>

                                {{-- TRACABILITÉ : NUMÉRO DE LOT ET EXPIRATION --}}
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Numéro de Lot (Traçabilité)") }}</label>
                                    <input type="text" name="batch_number" value="{{ old('batch_number', $health->batch_number) }}" placeholder="{{ __("EX: LOT-2024-X") }}"
                                           class="w-full p-5 bg-slate-50 rounded-2xl border-none shadow-inner font-black uppercase italic focus:ring-4 focus:ring-blue-500/10 transition outline-none">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Date de péremption") }}</label>
                                    <input type="date" name="expiry_date" value="{{ old('expiry_date', $health->expiry_date ? $health->expiry_date->format('Y-m-d') : '') }}"
                                           class="w-full p-5 bg-slate-50 rounded-2xl border-none shadow-inner font-black italic focus:ring-4 focus:ring-blue-500/10 transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Mode d'administration") }}</label>
                                    <select name="mode_administration" required class="w-full p-5 bg-slate-50 rounded-2xl font-black border-none text-slate-700 shadow-inner italic focus:ring-4 focus:ring-emerald-500/10 transition appearance-none cursor-pointer">
                                        @foreach(['Eau de boisson', 'Injection', 'Nébulisation', 'Aliment', 'Oculaire', 'Spray'] as $mode)
                                            <option value="{{ $mode }}" {{ old('mode_administration', $health->mode_administration) == $mode ? 'selected' : '' }}>{{ __($mode) }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Coût total") }} ({{ currency() }})</label>
                                    <div class="relative">
                                        <input type="number" name="cost" value="{{ old('cost', (float)$health->cost) }}" min="0" step="0.01" required
                                               class="w-full p-5 bg-slate-50 rounded-2xl font-black text-3xl border-none text-emerald-600 shadow-inner italic focus:ring-4 focus:ring-emerald-500/10 transition outline-none pr-16">
                                        <span class="absolute right-6 top-1/2 -translate-y-1/2 text-slate-300 font-black italic uppercase leading-none text-xs">{{ currency() }}</span>
                                    </div>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic tracking-widest leading-none">{{ __("Observations / Rapport d'acte") }}</label>
                                    <textarea name="observations" rows="4" 
                                              class="w-full p-6 bg-slate-50 rounded-[2.5rem] border-none shadow-inner font-bold text-slate-600 italic focus:bg-white transition outline-none">{{ old('observations', $health->observations) }}</textarea>
                                </div>
                            </div>
                        </div>

                        {{-- ACTIONS --}}
                        <div class="flex flex-col md:flex-row gap-5 pt-4">
                            <a href="{{ route('health.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-8 rounded-[2.5rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-[0.2em] text-[10px] italic transition flex items-center justify-center no-underline leading-none">
                                {{ __("Annuler") }}
                            </a>
                            <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-8 rounded-[2.5rem] hover:bg-blue-600 active:scale-95 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group cursor-pointer">
                                <i class="fas fa-sync-alt mr-3 group-hover:rotate-180 transition-transform duration-700"></i>
                                {{ __("Mettre à jour l'intervention") }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>