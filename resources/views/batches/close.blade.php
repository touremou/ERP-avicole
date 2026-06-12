<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('batches.show', $batch->id) }}" class="group text-slate-400 hover:text-slate-800 transition no-underline">
                    <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform"></i>
                </a>
                <div class="text-left">
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        {{ __("Bilan de fin de cycle :") }} <span class="text-orange-500">{{ $batch->code }}</span>
                    </h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-2 italic">{{ __("Compte de résultat & clôture") }}</p>
                </div>
            </div>
            <span class="hidden md:inline px-4 py-2 bg-orange-50 rounded-xl text-[10px] font-black uppercase text-orange-600 italic tracking-widest border border-orange-100">
                {{ $batch->building->name ?? '—' }} | {{ ucfirst($batch->type) }} | {{ $costs['duration_days'] ?? 0 }}j
            </span>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8" x-data="closingForm()" x-cloak>

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-600 text-white rounded-[2rem] text-[10px] font-black uppercase tracking-widest shadow-xl">
                    @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
                </div>
            @endif

            @can('elevage.M')
            <form action="{{ route('batches.close', $batch->id) }}" method="POST" onsubmit="return confirm('{{ __("Confirmer la clôture ? Le bâtiment passera en désinfection.") }}');">
                @csrf @method('PUT')

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-6">

                        {{-- 01. RÉSUMÉ TECHNIQUE --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Initial") }}</p>
                                <p class="text-2xl font-black text-slate-800">{{ number_format($batch->initial_quantity) }}</p>
                            </div>
                            <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                                <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Vendables") }}</p>
                                <p class="text-2xl font-black text-emerald-600">{{ number_format($remainingBirds) }}</p>
                            </div>
                            <div class="bg-white p-5 rounded-[2rem] border border-red-100 shadow-sm text-center">
                                <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-1">{{ __("Pertes") }}</p>
                                <p class="text-2xl font-black text-red-600">{{ number_format($totalMortality) }}</p>
                                <p class="text-[7px] text-slate-400">{{ number_format($batch->mortality_rate ?? 0, 2) }}%</p>
                            </div>
                            <div class="bg-white p-5 rounded-[2rem] border border-blue-100 shadow-sm text-center">
                                <p class="text-[8px] font-black text-blue-400 uppercase tracking-widest mb-1">{{ __("Aliment") }}</p>
                                <p class="text-2xl font-black text-blue-600">{{ number_format($totalFeed, 0) }}</p>
                                <p class="text-[7px] text-slate-400">{{ __("kg consommés") }}</p>
                            </div>
                        </div>

                        {{-- 02. COMPTE DE RÉSULTAT (P&L) --}}
                        <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm text-left">
                            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-6 italic flex items-center gap-2">
                                <i class="fa-solid fa-calculator text-orange-500"></i> {{ __("Compte de résultat") }}
                            </h3>

                            {{-- CHARGES --}}
                            <div class="mb-6">
                                <p class="text-[9px] font-black text-red-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                                    <span class="w-3 h-3 bg-red-500 rounded-full"></span> {{ __("Charges") }}
                                </p>
                                <div class="space-y-2 ml-5">
                                    <div class="flex justify-between items-center p-3 bg-red-50/50 rounded-xl">
                                        <div>
                                            <span class="text-[10px] font-black text-slate-700">{{ __("🐣 Acquisition poussins") }}</span>
                                            <p class="text-[8px] text-slate-400">{{ number_format($batch->initial_quantity) }} × {{ number_format($batch->buy_price_per_unit ?? 0, 0, ',', '.') }} GNF</p>
                                        </div>
                                        <span class="text-sm font-black text-red-600">{{ number_format($costs['acquisition'], 0, ',', '.') }} GNF</span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-red-50/50 rounded-xl">
                                        <div>
                                            <span class="text-[10px] font-black text-slate-700">{{ __("🌾 Alimentation") }}</span>
                                            <p class="text-[8px] text-slate-400">{{ number_format($costs['feed_kg'], 0) }} kg × {{ number_format($costs['feed_price_kg'], 0, ',', '.') }} GNF/kg (moy.)</p>
                                        </div>
                                        <span class="text-sm font-black text-red-600">{{ number_format($costs['feed'], 0, ',', '.') }} GNF</span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-red-50/50 rounded-xl">
                                        <div>
                                            <span class="text-[10px] font-black text-slate-700">{{ __("💊 Santé / Vétérinaire") }}</span>
                                            <p class="text-[8px] text-slate-400">{{ __("Traitements, vaccins, médicaments") }}</p>
                                        </div>
                                        <span class="text-sm font-black text-red-600">{{ number_format($costs['health'], 0, ',', '.') }} GNF</span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-red-50/50 rounded-xl">
                                        <div>
                                            <span class="text-[10px] font-black text-slate-700">{{ __("⚡ Énergie (prorata)") }}</span>
                                            {{-- Utilisation de round() pour enlever les décimales --}}
                                            <p class="text-[8px] text-slate-400">{{ __(":daysj × quote-part énergie", ['days' => round($costs['duration_days'])]) }}</p>
                                        </div>
                                        <span class="text-sm font-black text-red-600">{{ number_format($costs['energy'], 0, ',', '.') }} GNF</span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-amber-50 rounded-xl">
                                        <div>
                                            <span class="text-[10px] font-black text-slate-700">{{ __("📋 Frais annexes") }}</span>
                                            <p class="text-[8px] text-slate-400">{{ __("Main d'œuvre, transport, divers") }}</p>
                                        </div>
                                        <input type="number" name="additional_costs" x-model.number="additionalCosts" value="{{ old('additional_costs', 0) }}" min="0"
                                            class="w-40 bg-white border border-amber-200 rounded-xl p-2 text-sm font-black text-red-600 text-right outline-none focus:border-amber-500">
                                    </div>
                                </div>
                                <div class="flex justify-between items-center mt-3 p-4 bg-red-100 rounded-2xl ml-5">
                                    <span class="text-[10px] font-black text-red-700 uppercase tracking-widest">{{ __("Total Charges") }}</span>
                                    <span class="text-lg font-black text-red-700" x-text="formatGNF(totalCharges)"></span>
                                </div>
                            </div>

                            {{-- PRODUITS --}}
                            <div>
                                <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                                    <span class="w-3 h-3 bg-emerald-500 rounded-full"></span> {{ __("Produits (Recettes)") }}
                                </p>
                                <div class="space-y-2 ml-5">
                                    <div class="flex justify-between items-center p-3 bg-emerald-50/50 rounded-xl">
                                        <div>
                                            <span class="text-[10px] font-black text-slate-700">{{ __("💰 Vente volaille") }}</span>
                                            <p class="text-[8px] text-slate-400"><span x-text="remainingBirds"></span> {{ __("sujets × prix unitaire") }}</p>
                                        </div>
                                        <input type="number" name="actual_sell_price_per_unit" x-model.number="sellPrice" value="{{ old('actual_sell_price_per_unit') }}" min="0" required placeholder="{{ __("Prix/sujet") }}"
                                            class="w-40 bg-white border border-emerald-200 rounded-xl p-2 text-sm font-black text-emerald-600 text-right outline-none focus:border-emerald-500">
                                    </div>
                                </div>
                                <div class="flex justify-between items-center mt-3 p-4 bg-emerald-100 rounded-2xl ml-5">
                                    <span class="text-[10px] font-black text-emerald-700 uppercase tracking-widest">{{ __("Chiffre d'Affaires") }}</span>
                                    <span class="text-lg font-black text-emerald-700" x-text="formatGNF(revenue)"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- SIDEBAR : RÉSULTAT + ACTIONS --}}
                    <div class="space-y-6">
                        {{-- RÉSULTAT NET --}}
                        <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl border border-slate-800">
                            <h3 class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-6">{{ __("Résultat") }}</h3>

                            <div class="space-y-4">
                                <div class="flex justify-between"><span class="text-[9px] text-slate-500 uppercase font-black">{{ __("CA Brut") }}</span><span class="text-sm font-black text-white" x-text="formatGNF(revenue)"></span></div>
                                <div class="flex justify-between"><span class="text-[9px] text-red-400 uppercase font-black">{{ __("Charges") }}</span><span class="text-sm font-black text-red-400" x-text="'- ' + formatGNF(totalCharges)"></span></div>
                                <div class="border-t border-slate-700 pt-4 flex justify-between">
                                    <span class="text-[9px] uppercase font-black" :class="netProfit >= 0 ? 'text-emerald-400' : 'text-red-400'">
                                        <span x-text="netProfit >= 0 ? {{ Js::from(__("✅ BÉNÉFICE")) }} : {{ Js::from(__("🔴 PERTE")) }}"></span>
                                    </span>
                                    <span class="text-2xl font-black tracking-tighter" :class="netProfit >= 0 ? 'text-emerald-400' : 'text-red-400'" x-text="formatGNF(netProfit)"></span>
                                </div>
                            </div>

                            <div class="mt-6 grid grid-cols-2 gap-3">
                                <div class="p-4 bg-white/5 rounded-2xl text-center">
                                    <p class="text-[7px] font-black text-slate-500 uppercase">ROI</p>
                                    <p class="text-lg font-black" :class="roi >= 0 ? 'text-emerald-400' : 'text-red-400'" x-text="roi.toFixed(1) + '%'"></p>
                                </div>
                                <div class="p-4 bg-white/5 rounded-2xl text-center">
                                    <p class="text-[7px] font-black text-slate-500 uppercase">Coût / sujet</p>
                                    <p class="text-lg font-black text-amber-400" x-text="formatGNF(costPerBird)"></p>
                                </div>
                                <div class="p-4 bg-white/5 rounded-2xl text-center">
                                    <p class="text-[7px] font-black text-slate-500 uppercase">Marge / sujet</p>
                                    <p class="text-lg font-black" :class="marginPerBird >= 0 ? 'text-emerald-400' : 'text-red-400'" x-text="formatGNF(marginPerBird)"></p>
                                </div>
                                <div class="p-4 bg-white/5 rounded-2xl text-center">
                                    <p class="text-[7px] font-black text-slate-500 uppercase">IC (Feed Conv.)</p>
                                    <p class="text-lg font-black text-blue-400" x-text="ic.toFixed(2)"></p>
                                    <p class="text-[7px] text-slate-500">kg aliment/kg vif</p>
                                </div>
                            </div>
                        </div>

                        {{-- DATE DE CLÔTURE --}}
                        <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-left">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2 mb-2 block">Date de clôture</label>
                            <input type="date" name="closing_date" value="{{ date('Y-m-d') }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>

                        {{-- BOUTONS --}}
                        <button type="submit" class="w-full bg-slate-900 text-white font-black py-8 rounded-[2rem] hover:bg-blue-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                            <i class="fas fa-file-invoice-dollar mr-2"></i> Clôturer & Archiver
                        </button>
                        <a href="{{ route('batches.show', $batch->id) }}" class="w-full block bg-white border border-slate-200 text-slate-400 font-black py-5 rounded-[2rem] hover:bg-red-50 hover:text-red-500 text-center uppercase tracking-widest text-[9px] italic no-underline transition-all">
                            Annuler
                        </a>
                    </div>
                </div>
            </form>
            @else
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2">Accès restreint</h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest italic">Seuls les profils autorisés peuvent clôturer un cycle.</p>
            </div>
            @endcan
        </div>
    </div>

    <script>
    function closingForm() {
        return {
            sellPrice: {{ old('actual_sell_price_per_unit', 0) }},
            additionalCosts: {{ old('additional_costs', 0) }},
            remainingBirds: {{ $remainingBirds }},
            knownCosts: {{ $costs['total_known'] }},
            totalFeedKg: {{ $totalFeed }},

            get revenue() { return this.sellPrice * this.remainingBirds; },
            get totalCharges() { return this.knownCosts + this.additionalCosts; },
            get netProfit() { return this.revenue - this.totalCharges; },
            get roi() { return this.totalCharges > 0 ? (this.netProfit / this.totalCharges) * 100 : 0; },
            get costPerBird() { return this.remainingBirds > 0 ? this.totalCharges / this.remainingBirds : 0; },
            get marginPerBird() { return this.sellPrice - this.costPerBird; },
            get ic() {
                // Indice de consommation : kg aliment / kg poids vif estimé
                const avgWeight = {{ $batch->type === 'chair' ? setting('elevage.avg_weight_chair', 2.2) : setting('elevage.avg_weight_ponte', 1.8) }}; // kg estimé
                const totalLiveWeight = this.remainingBirds * avgWeight;
                return totalLiveWeight > 0 ? this.totalFeedKg / totalLiveWeight : 0;
            },

            formatGNF(v) { return new Intl.NumberFormat('fr-GN', { maximumFractionDigits: 0 }).format(Math.round(v)) + ' GNF'; },
        }
    }
    </script>
</x-app-layout>
