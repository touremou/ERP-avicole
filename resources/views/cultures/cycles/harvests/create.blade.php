<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <x-page-header :title="__('Saisir une récolte')" :subtitle="$cycle->crop_name . ' · ' . $cycle->plot?->name" icon="fa-wheat-awn" accent="green" :back="route('crop-cycles.show', $cycle)" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('crop-cycles.harvests.store', $cycle) }}" method="POST" x-data="{ sync: false, unit: '{{ old('unit', 'kg') }}', dest: '{{ old('destination', 'vente') }}',
                          get held() { return this.dest !== 'vente' },
                          get needsWeight() { return this.held && this.unit.trim().toLowerCase() !== 'kg' } }" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                {{-- DESTINATION (T1) — décide si cette récolte est un REVENU ou un
                     STOCK. Placée en tête : elle change le sens de tout le reste
                     du formulaire (prix de vente, pesée obligatoire). --}}
                <div class="bg-slate-900 rounded-[2rem] p-6 shadow-inner">
                    <label class="block text-[9px] font-black text-white/40 uppercase ml-2 mb-3 italic">{{ __("Que devient cette récolte ? *") }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        @foreach(\App\Models\Harvest::DESTINATIONS as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="destination" value="{{ $value }}" x-model="dest" class="peer sr-only" @checked(old('destination', 'vente') === $value)>
                                <span class="block text-center p-4 rounded-2xl text-[10px] font-black uppercase italic tracking-tight bg-white/10 text-white/50 peer-checked:bg-green-500 peer-checked:text-white transition-all">
                                    {{ __($label) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p x-show="held" x-cloak class="text-[9px] font-bold text-amber-300 uppercase ml-2 mt-4 italic leading-relaxed">
                        <i class="fa-solid fa-circle-info mr-1"></i>
                        {{ __("Récolte NON vendue : aucun revenu n'est inscrit au cycle. Elle entre en stock, valorisée au coût de production — la marge se fera à la vente réelle.") }}
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de récolte *") }}</label>
                        <input type="date" name="harvest_date" value="{{ old('harvest_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité *") }}</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity') }}" required class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="unit" x-model="unit" value="{{ old('unit', 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center" placeholder="kg">
                        </div>
                    </div>
                    {{-- Poids net pesé : déduit automatiquement si la quantité est
                         en kg ; à saisir si l'unité est autre (caisses, sacs…)
                         pour garder le rendement kg/ha exact. --}}
                    <div x-show="unit.trim().toLowerCase() !== 'kg'" x-cloak>
                        <label class="block text-[9px] font-black text-amber-500 uppercase ml-2 mb-1 italic">
                            {{ __("Poids net pesé (kg)") }}<span x-show="needsWeight"> *</span>
                        </label>
                        <input type="number" step="0.001" min="0" name="net_weight_kg" value="{{ old('net_weight_kg') }}" :required="needsWeight" class="w-full bg-amber-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right" placeholder="0.000">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic" x-show="!needsWeight">{{ __("Pour le calcul du rendement kg/ha") }}</p>
                        <p class="text-[8px] font-black text-rose-500 uppercase ml-2 mt-1 italic" x-show="needsWeight" x-cloak>
                            {{ __("Obligatoire : une récolte conservée doit être pesée pour être valorisée.") }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Pertes") }}</label>
                        <input type="number" step="0.001" min="0" name="loss_quantity" value="{{ old('loss_quantity', 0) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Qualité") }}</label>
                        <select name="quality" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($qualities as $q)
                                <option value="{{ $q }}" @selected(old('quality') == $q)>{{ ucfirst($q) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Responsable") }}</label>
                        <select name="employee_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Le prix n'existe que sur une VENTE : sur une récolte
                         conservée il serait tôt ou tard resommé comme un revenu. --}}
                    <div x-show="!held" x-cloak>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Prix de vente encaissé") }} ({{ $currency }}/kg)</label>
                        <input type="number" step="1" min="0" name="unit_price" value="{{ old('unit_price') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                </div>

                <div class="bg-slate-50 rounded-[2rem] p-6 shadow-inner space-y-4">
                    <div class="flex items-center gap-3" x-show="!held">
                        <input type="hidden" name="sync_to_stock" value="0">
                        <input type="checkbox" name="sync_to_stock" value="1" id="sync_to_stock" x-model="sync" class="rounded">
                        <label for="sync_to_stock" class="text-[9px] font-black text-slate-500 uppercase italic cursor-pointer">{{ __("Intégrer au stock (Récoltes)") }}</label>
                    </div>
                    {{-- Récolte conservée : l'entrée en stock n'est plus une option.
                         Sortie du revenu, la matière doit être quelque part. --}}
                    <p x-show="held" x-cloak class="text-[9px] font-black text-green-600 uppercase italic">
                        <i class="fa-solid fa-check mr-1"></i> {{ __("Entrée en stock automatique (Récoltes)") }}
                    </p>
                    <div x-show="sync || held" x-cloak>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de l'article en stock") }}</label>
                        <input type="text" name="stock_item_name" value="{{ old('stock_item_name', $cycle->crop_name) }}" class="w-full bg-white border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-wheat-awn mr-2 text-amber-400"></i> {{ __("Enregistrer la récolte") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
