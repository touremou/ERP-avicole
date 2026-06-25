<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            {{-- RETOUR DYNAMIQUE --}}
            <a href="{{ route('batches.show', $batch->id) }}" class="group text-slate-400 hover:text-slate-800 transition no-underline">
                <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform text-xl"></i>
            </a>
            <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                <i class="fa-solid fa-pen-to-square text-orange-500 mr-2"></i> {{ __("Modifier Ravitaillement") }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 text-left">
            
            {{-- BLOC ERREURS (L) --}}
            @if ($errors->any())
                <div class="mb-6 p-5 bg-rose-600 text-white rounded-[2rem] shadow-xl text-[10px] font-black uppercase italic tracking-widest animate-pulse">
                    <p class="mb-2 border-b border-white/20 pb-2">⚠️ {{ __("Erreurs de validation :") }}</p>
                    <ul class="list-none p-0">
                        @foreach ($errors->all() as $error) 
                            <li class="mt-1">• {{ $error }}</li> 
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Permission M : Modification --}}
            @can('provenderie.M')
            <form action="{{ route('feed-purchases.update', $feedPurchase->id) }}" method="POST" 
                  class="bg-white p-10 rounded-[3.5rem] shadow-2xl border border-slate-100 space-y-8"
                  x-data="{ 
                      quantity: {{ $feedPurchase->quantity }},
                      totalPrice: {{ $feedPurchase->unit_price }},
                      get unitCost() { return this.quantity > 0 ? Math.round(this.totalPrice / this.quantity) : 0 }
                  }">
                @csrf
                @method('PUT')

                {{-- Rappel du secteur avec icône dynamique --}}
                <div class="flex items-center gap-3 px-5 py-2.5 bg-slate-900 rounded-full w-fit border border-slate-800 mb-6 shadow-lg">
                    <i @class([
                        'fa-solid text-[11px]',
                        'fa-egg text-emerald-400' => in_array($batch->type, ['Ponte', 'Reproducteur']),
                        'fa-drumstick-bite text-orange-400' => !in_array($batch->type, ['Ponte', 'Reproducteur'])
                    ])></i>
                    <span class="text-[9px] uppercase font-black tracking-[0.2em] text-white italic">{{ __("Secteur") }} {{ $batch->type }} • {{ __("Bande") }} {{ $batch->code }}</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    {{-- DÉSIGNATION DE L'ARTICLE (L) --}}
                    <div class="space-y-3">
                        <label class="block text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Désignation de l'Article") }}</label>
                        <select name="feed_type" required class="w-full bg-slate-50 border-none rounded-2xl p-5 text-sm font-black uppercase focus:ring-4 focus:ring-blue-500/10 shadow-inner appearance-none transition-all italic text-slate-800 cursor-pointer">
                            @php
                                $isLayerType = in_array($batch->type, ['Ponte', 'Reproducteur']);
                                $phases = $isLayerType
                                    ? ['Ponte Démarrage (Poussin)', 'Ponte Croissance (Poulette)', 'Ponte 1 (Pic de ponte)', 'Ponte 2 (Entretien)']
                                    : ['Chair Démarrage', 'Chair Croissance', 'Chair Finition'];
                                $currentValue = trim($feedPurchase->feed_type);
                            @endphp

                            <option value="">-- {{ __("CHOISIR L'ALIMENT") }} --</option>
                            @foreach($phases as $phase)
                                <option value="{{ $phase }}" {{ ($currentValue === $phase || str_contains($currentValue, str_replace(['Chair ', 'Ponte '], '', $phase))) ? 'selected' : '' }}>
                                    {{ $phase }}
                                </option>
                            @endforeach

                            @if(!in_array($currentValue, $phases) && !empty($currentValue))
                                <option value="{{ $currentValue }}" selected>⭐ {{ strtoupper($currentValue) }} ({{ __("Valeur actuelle") }})</option>
                            @endif
                        </select>
                    </div>

                    {{-- DATE ACHAT (M) --}}
                    <div class="space-y-3">
                        <label class="block text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Date de l'opération") }}</label>
                        <input type="date" name="purchase_date" 
                            value="{{ \Carbon\Carbon::parse($feedPurchase->purchase_date)->format('Y-m-d') }}" 
                            class="w-full bg-slate-50 border-none rounded-2xl p-5 text-sm font-black shadow-inner italic text-slate-800 focus:ring-4 focus:ring-blue-500/10 transition-all">
                    </div>
                </div>

                {{-- FOURNISSEUR --}}
                <div class="space-y-3">
                    <label class="block text-[10px] font-black text-slate-400 uppercase ml-2 italic tracking-widest">{{ __("Fournisseur Partenaire") }}</label>
                    <select name="supplier" required class="w-full bg-slate-50 border-none rounded-2xl p-5 text-sm font-black uppercase shadow-inner italic text-slate-800 appearance-none focus:ring-4 focus:ring-blue-500/10 transition-all cursor-pointer">
                        <option value="">-- {{ __("SÉLECTIONNER LE PRESTATAIRE") }} --</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->name }}" {{ ($feedPurchase->supplier ?? $feedPurchase->metadata['supplier'] ?? '') == $provider->name ? 'selected' : '' }}>
                                {{ $provider->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-8 bg-slate-900 p-8 rounded-[3rem] shadow-xl relative overflow-hidden">
                    {{-- QUANTITÉ (KG) --}}
                    <div class="relative z-10">
                        <label class="block text-[10px] font-black text-blue-400 uppercase mb-4 text-center italic tracking-widest">{{ __("Poids Total (KG)") }}</label>
                        <input type="number" min="0" name="quantity" step="0.001" x-model="quantity" class="w-full bg-white/10 border-none rounded-2xl p-5 text-2xl font-black text-center text-white focus:ring-4 focus:ring-blue-500/30 transition-all italic">
                        <p class="text-[8px] text-slate-500 mt-3 text-center uppercase font-black italic">{{ __("Précision à 1 gramme (± 0.001)") }}</p>
                    </div>

                    {{-- MONTANT TOTAL ({{ currency() }}) --}}
                    <div class="relative z-10">
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-4 text-center italic tracking-widest">{{ __("Montant Payé") }} ({{ currency() }})</label>
                        <input type="number" min="0" name="unit_price" step="1" x-model="totalPrice" class="w-full bg-white/10 border-none rounded-2xl p-5 text-2xl font-black text-center text-white focus:ring-4 focus:ring-emerald-500/30 transition-all italic">
                        <p class="text-[8px] text-emerald-500 mt-3 text-center uppercase font-black italic">{{ __("Coût") }} : <span x-text="unitCost.toLocaleString()"></span> {{ currency() }} / KG</p>
                    </div>
                    <i class="fa-solid fa-scale-balanced absolute -right-4 -bottom-4 text-white/5 text-9xl"></i>
                </div>

                {{-- Métadonnées de sécurité --}}
                <input type="hidden" name="metadata[conso_type]" value="{{ $feedPurchase->metadata['conso_type'] ?? 'Aliment' }}">
                <input type="hidden" name="metadata[poultry_type]" value="{{ $batch->type }}">

                {{-- BOUTONS D'ACTION --}}
                <div class="flex flex-col md:flex-row gap-5 pt-6">
                    <a href="{{ route('batches.show', $batch->id) }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-7 rounded-[2.5rem] shadow-sm hover:bg-slate-50 transition-all text-center uppercase tracking-[0.2em] text-[10px] italic flex items-center justify-center gap-3 no-underline">
                        <i class="fas fa-times"></i> {{ __("Abandonner") }}
                    </a>
                    <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-7 rounded-[2.5rem] hover:bg-emerald-600 active:scale-95 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group">
                        <span class="flex items-center justify-center gap-4">
                            {{ __("Mettre à jour le registre") }}
                            <i class="fas fa-sync-alt group-hover:rotate-180 transition-transform duration-700"></i>
                        </span>
                    </button>
                </div>
            </form>
            @else
            {{-- ACCÈS REFUSÉ (M MANQUANT) --}}
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic font-black">
                <i class="fas fa-lock text-slate-200 text-6xl mb-8"></i>
                <h3 class="text-xl text-slate-800 uppercase italic tracking-tighter mb-2">{{ __("Accès Restreint") }}</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest leading-relaxed">{{ __("La permission") }} <span class="text-orange-500">provenderie.M</span> ({{ __("Modifier") }}) {{ __("est requise pour modifier ce ravitaillement.") }}</p>
                <a href="{{ route('batches.show', $batch->id) }}" class="inline-block mt-10 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] uppercase italic no-underline">{{ __("Retour à la fiche") }}</a>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>