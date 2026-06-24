<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 text-left">
                <a href="{{ route('batches.show', $check->batch_id) }}" class="group text-slate-400 hover:text-slate-800 transition no-underline">
                    <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform text-xl"></i>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        📊 {{ __('Rectification du Pointage') }}
                    </h2>
                    <p class="text-[10px] font-black text-orange-500 uppercase tracking-widest mt-1 italic leading-none">
                        {{ __("Session du") }} {{ \Carbon\Carbon::parse($check->check_date)->format(setting('general.date_format', 'd/m/Y')) }} • {{ __("Lot") }} : {{ $check->batch->code }}
                    </p>
                </div>
            </div>
            <div class="hidden md:flex items-center gap-3">
                <div class="text-right">
                    <p class="text-[8px] font-black text-slate-400 uppercase leading-none">{{ __("Effectif actuel") }}</p>
                    <p class="text-sm font-black text-slate-700 italic leading-none">{{ number_format($check->batch->current_quantity) }} {{ __("têtes") }}</p>
                </div>
                <span class="px-4 py-2 bg-orange-900 text-amber-400 rounded-xl text-[10px] font-black uppercase italic tracking-widest border border-orange-800 shadow-lg leading-none">
                    <i class="fas fa-exclamation-triangle mr-2"></i> {{ __("Mode Correction") }} ({{ ucfirst($check->batch->type) }})
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @can('elevage.M')
                @if ($errors->any())
                    <div class="mb-8 p-6 bg-red-600 text-white rounded-[2.5rem] shadow-xl text-left">
                        <p class="text-[10px] font-black uppercase italic mb-2">❌ {{ __("Erreurs de validation détectées") }} :</p>
                        <ul class="list-disc list-inside text-xs font-black uppercase tracking-tight">
                            @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Bannière d'erreur mortalité (non-bloquant, remplace alert()) --}}
                <div id="mortality-error-banner" class="hidden mb-6 p-5 bg-red-50 border-2 border-red-400 text-red-700 rounded-[2rem] text-left shadow-sm">
                    <p class="text-[10px] font-black uppercase italic leading-none">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span id="mortality-error-msg">{{ __("Mortalité supérieure à l'effectif disponible.") }}</span>
                    </p>
                </div>

                <form action="{{ route('daily-checks.update', $check->id) }}" method="POST" class="space-y-8" id="edit-precision-form">
                    @csrf
                    @method('PUT')

                    <input type="hidden" name="batch_id" value="{{ $check->batch_id }}">
                    <input type="hidden" id="current_stock_birds" value="{{ $check->batch->current_quantity }}">
                    <input type="hidden" id="original_mortality" value="{{ $check->mortality }}">
                    @php $mortalityAlert = (float) setting('elevage.mortality_alert', 5); @endphp
                    <input type="hidden" id="mortality_alert_threshold" value="{{ $mortalityAlert }}">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-left">
                        {{-- MORTALITÉ --}}
                        <div id="mortality-card" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm transition-all relative">
                            <div class="flex justify-between items-center mb-4">
                                <label class="text-[10px] font-black text-red-500 uppercase tracking-widest italic leading-none">{{ __("Mortalité rectifiée (Têtes)") }}</label>
                                <div class="text-right">
                                    <span id="mortality-pct" class="text-[9px] font-black text-slate-300 uppercase italic leading-none">0% {{ __("du lot") }}</span>
                                    <span id="mortality-alert-badge" class="hidden ml-2 px-2 py-0.5 bg-red-500 text-white rounded-lg text-[8px] font-black uppercase">
                                        ⚠️ {{ __("SEUIL ALERTE") }} {{ $mortalityAlert }}%
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <button type="button" onclick="changeEditVal('mortality', -1)" class="w-14 h-14 shrink-0 rounded-2xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-all font-black text-xl flex items-center justify-center">-</button>
                                <input type="number" name="mortality" id="mortality" value="{{ old('mortality', $check->mortality) }}" required oninput="updateEditStats()"
                                       class="w-full text-7xl font-black text-slate-800 outline-none text-center bg-transparent border-none p-0 focus:ring-0 appearance-none leading-none italic">
                                <button type="button" onclick="changeEditVal('mortality', 1)" class="w-14 h-14 shrink-0 rounded-2xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-all font-black text-xl flex items-center justify-center">+</button>
                            </div>
                        </div>

                        {{-- ALIMENTATION --}}
                        <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-blue-500 uppercase mb-4 tracking-widest italic ml-1 leading-none">{{ __("Aliment rectifié (Kg)") }}</label>
                                <div class="flex items-center justify-between gap-4">
                                    <button type="button" onclick="changeEditVal('feed_consumed', -1)" class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-all font-black text-lg">-1</button>
                                    <input type="number" name="feed_consumed" id="feed_consumed" value="{{ old('feed_consumed', $check->feed_consumed) }}" step="0.1" required oninput="updateEditStats()"
                                           class="w-full text-center text-5xl font-black text-slate-800 outline-none bg-transparent border-none focus:ring-0 p-0 m-0 leading-none italic">
                                    <button type="button" onclick="changeEditVal('feed_consumed', 5)" class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-all font-black text-lg">+5</button>
                                </div>
                            </div>

                            <div class="pt-4 border-t border-slate-50">
                                <label class="block text-[9px] font-black text-slate-400 uppercase mb-3 tracking-widest leading-none italic">
                                    {{ __("Type d'Aliment (Silo") }} : {{ $check->batch->type }})
                                </label>
                                <select name="feed_type" id="feed_type" required onchange="updateEditStats()"
                                        class="w-full p-4 bg-slate-50 border-none rounded-2xl font-black text-[10px] uppercase focus:ring-2 focus:ring-blue-500 shadow-inner appearance-none transition-all cursor-pointer italic text-left outline-none">
                                    @foreach($phases ?? [] as $phaseName)
                                        @php
                                            $qtyInStock = $stockData[$phaseName] ?? 0;
                                            $virtualStock = $qtyInStock;
                                            if(trim($check->feed_type) === trim($phaseName)) {
                                                $virtualStock += (float)$check->feed_consumed;
                                            }
                                        @endphp
                                        <option value="{{ $phaseName }}" data-stock="{{ $virtualStock }}"
                                            {{ old('feed_type', $check->feed_type) == $phaseName ? 'selected' : '' }}>
                                            {{ str_replace(['Chair ', 'Ponte '], '', $phaseName) }} • {{ __("Stock") }} : {{ number_format($virtualStock, 1) }} kg
                                        </option>
                                    @endforeach
                                </select>
                                <div id="stock-warning" class="hidden mt-2 p-3 bg-red-50 rounded-xl border border-red-100">
                                    <p id="stock-warning-msg" class="text-[8px] text-red-600 font-black uppercase italic leading-none animate-pulse">
                                        ⚠️ {{ __("Stock Magasin insuffisant !") }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- PARAMÈTRES AMBIANCE --}}
                    <div x-data="{ litterChanged: {{ old('litter_changed', $check->litter_changed) ? 'true' : 'false' }} }" class="bg-slate-900 p-10 rounded-[4rem] text-white shadow-2xl relative text-left italic">
                        <div class="absolute right-0 bottom-0 opacity-10 p-8 scale-150 pointer-events-none"><i class="fas fa-wind"></i></div>
                        <div class="flex justify-between items-center mb-8">
                            <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-[0.3em] leading-none">{{ __("Correction Ambiance") }}</h3>
                            <label class="flex items-center gap-3 bg-white/5 px-5 py-2.5 rounded-2xl cursor-pointer hover:bg-white/10 transition border border-white/10 group">
                                <input type="checkbox" name="litter_changed" value="1" x-model="litterChanged" class="rounded border-none bg-white/20 text-blue-500 focus:ring-0">
                                <span class="text-[9px] font-black uppercase italic tracking-widest text-slate-300 leading-none mt-0.5">{{ __("Litière Changée") }}</span>
                            </label>
                        </div>

                        {{-- Fumier ramassé : valorisé en stock fertilisant à la coche « Litière changée » --}}
                        <div x-show="litterChanged" x-cloak class="mb-8 px-5 py-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                            <label class="block text-[8px] font-black text-emerald-300 uppercase tracking-widest mb-2 leading-none">{{ __("Fumier ramassé (Kg) — vendable comme fertilisant") }}</label>
                            <input type="number" name="manure_collected_kg" min="0" step="0.1" value="{{ old('manure_collected_kg', $check->manure_collected_kg) }}" placeholder="0"
                                   class="w-full bg-white/5 border border-white/10 p-4 rounded-2xl text-xl text-emerald-300 text-center outline-none italic font-black">
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 relative z-10">
                            <div class="space-y-2 text-center">
                                <label class="block text-[9px] text-slate-500 uppercase tracking-widest leading-none">{{ __("Eau (L)") }}</label>
                                <input type="number" name="water_consumed" value="{{ old('water_consumed', $check->water_consumed) }}" step="0.1" class="w-full bg-white/10 p-4 rounded-2xl border-none outline-none font-black text-xl text-blue-400 text-center">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[9px] text-slate-500 uppercase tracking-widest text-center leading-none">{{ __("T° Min / Max") }}</label>
                                <div class="flex bg-white/5 rounded-2xl border border-white/10 overflow-hidden">
                                    <input type="number" name="temp_min" value="{{ old('temp_min', $check->temp_min) }}" step="0.1" class="w-1/2 bg-transparent border-none p-4 font-black text-cyan-400 text-center text-sm outline-none">
                                    <input type="number" name="temp_max" value="{{ old('temp_max', $check->temp_max) }}" step="0.1" class="w-1/2 bg-transparent border-none p-4 font-black text-orange-400 text-center text-sm outline-none border-l border-white/10">
                                </div>
                            </div>
                            <div class="space-y-2 text-center">
                                <label class="block text-[9px] text-slate-500 uppercase tracking-widest leading-none">{{ __("Humidité (%)") }}</label>
                                <input type="number" name="humidity" min="0" max="100" step="0.1" value="{{ old('humidity', $check->humidity) }}" class="w-full bg-white/10 p-4 rounded-2xl border-none outline-none font-black text-white text-center">
                            </div>
                            <div class="space-y-2 text-center">
                                <label class="block text-[9px] text-slate-500 uppercase tracking-widest leading-none">{{ __("Poids moyen / sujet (kg)") }}</label>
                                <input type="number" name="avg_weight" value="{{ old('avg_weight', $check->avg_weight) }}" step="0.001" title="{{ __('Poids moyen d\'un seul animal (moyenne d\'un échantillon pesé), pas le poids total du lot.') }}" class="w-full bg-white/10 p-4 rounded-2xl border-none outline-none font-black text-emerald-400 text-center font-mono">
                                <p class="text-[7px] font-bold text-slate-500 uppercase tracking-wide leading-tight italic">{{ __("Moyenne par tête, pas le poids du lot") }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- MOUVEMENTS & SOINS --}}
                    <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 text-left">
                        <h3 class="text-[10px] font-black uppercase text-orange-500 mb-8 tracking-[0.2em] flex items-center gap-2 leading-none">
                            <span class="w-2 h-2 bg-orange-500 rounded-full animate-ping"></span> {{ __("Mouvements & Soins (Rectification)") }}
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="p-5 bg-orange-50/50 rounded-3xl border border-orange-100">
                                    <label class="block text-[8px] font-black text-orange-400 uppercase mb-2 text-center leading-none">{{ __("Infirmerie (In)") }}</label>
                                    <input type="number" name="qty_quarantine_in" value="{{ old('qty_quarantine_in', $check->qty_quarantine_in) }}" min="0" class="w-full bg-transparent text-center text-2xl font-black text-orange-600 border-none outline-none p-0 leading-none">
                                </div>
                                <div class="p-5 bg-emerald-50/50 rounded-3xl border border-emerald-100">
                                    <label class="block text-[8px] font-black text-emerald-400 uppercase mb-2 text-center leading-none">{{ __("Rétablis (Out)") }}</label>
                                    <input type="number" name="qty_quarantine_out" value="{{ old('qty_quarantine_out', $check->qty_quarantine_out) }}" min="0" class="w-full bg-transparent text-center text-2xl font-black text-emerald-600 border-none outline-none p-0 leading-none">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <select name="treatment_type" class="p-4 bg-slate-50 rounded-2xl border-none font-black text-[10px] uppercase shadow-inner italic outline-none focus:ring-2 focus:ring-blue-500 appearance-none text-left">
                                    <option value="">-- {{ __("Acte") }} --</option>
                                    <option value="Vaccin" {{ old('treatment_type', $check->treatment_type) == 'Vaccin' ? 'selected' : '' }}>💉 {{ __("Vaccin") }}</option>
                                    <option value="Antibiotique" {{ old('treatment_type', $check->treatment_type) == 'Antibiotique' ? 'selected' : '' }}>💊 {{ __("Antibiotique") }}</option>
                                    <option value="Vitamine" {{ old('treatment_type', $check->treatment_type) == 'Vitamine' ? 'selected' : '' }}>✨ {{ __("Vitamine") }}</option>
                                </select>
                                <input type="text" name="treatment_name" value="{{ old('treatment_name', $check->treatment_name) }}" placeholder="{{ __('NOM DU PRODUIT') }}" class="p-4 bg-slate-50 rounded-2xl border-none font-black text-[10px] uppercase shadow-inner italic outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        {{-- Bien-être animal : boiterie & picage (sujets vivants en souffrance). --}}
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="p-4 bg-violet-50/60 rounded-2xl border border-violet-100">
                                <label class="block text-[8px] font-black text-violet-500 uppercase mb-2 text-center tracking-widest">{{ __('Boiteux') }}</label>
                                <input type="number" name="lame_count" value="{{ old('lame_count', $check->lame_count) }}" min="0" class="w-full bg-transparent text-center text-2xl font-black text-violet-600 border-none outline-none italic">
                            </div>
                            <div class="p-4 bg-fuchsia-50/60 rounded-2xl border border-fuchsia-100">
                                <label class="block text-[8px] font-black text-fuchsia-500 uppercase mb-2 text-center tracking-widest">{{ __('Picage / Blessés') }}</label>
                                <input type="number" name="pecking_injury_count" value="{{ old('pecking_injury_count', $check->pecking_injury_count) }}" min="0" class="w-full bg-transparent text-center text-2xl font-black text-fuchsia-600 border-none outline-none italic">
                            </div>
                        </div>

                        <textarea name="observations" rows="3" class="w-full bg-slate-50 rounded-[2rem] p-6 outline-none focus:bg-white border-2 border-transparent focus:border-blue-500 font-bold text-slate-600 transition shadow-inner text-xs uppercase italic"
                                  placeholder="{{ __('JUSTIFICATION OBLIGATOIRE DE LA RECTIFICATION (qui, pourquoi, source de données...)') }}">{{ old('observations', $check->observations) }}</textarea>
                        <p class="text-[8px] text-slate-300 font-black uppercase tracking-widest mt-2 italic leading-none">
                            <i class="fas fa-info-circle mr-1"></i> {{ __("Cette correction sera tracée avec votre identifiant et l'horodatage.") }}
                        </p>
                    </div>

                    {{-- ═══ SECTION CROISSANCE / NAISSANCES (Ruminants, Porcins, Lapins) ═══ --}}
                    @if($check->batch->isGmqTracked())
                    <div class="mt-8 bg-emerald-50 border border-emerald-200 rounded-[2rem] p-6">
                        <h3 class="text-[10px] font-black uppercase text-emerald-800 tracking-widest mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 bg-emerald-600 rounded-xl flex items-center justify-center text-white text-sm">{{ $check->batch->species?->icon ?? '🐑' }}</span>
                            {{ __("Suivi Naissances & Croissance") }}
                        </h3>
                        @php $ext = $check->extension; @endphp
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">{{ __("Naissances du jour") }}</label>
                                <input type="number" name="ext_qty_born" value="{{ old('ext_qty_born', $ext?->qty_born ?? 0) }}"
                                    min="0" class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">{{ __("Sevrages du jour") }}</label>
                                <input type="number" name="ext_qty_weaned" value="{{ old('ext_qty_weaned', $ext?->qty_weaned ?? 0) }}"
                                    min="0" class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            @if($check->batch->species?->tracks_milk)
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">{{ __("Production lait (litres)") }}</label>
                                <input type="number" name="ext_milk_liters" value="{{ old('ext_milk_liters', $ext?->milk_liters) }}"
                                    min="0" step="0.1" class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">{{ __("Taux Matière Grasse (%)") }}</label>
                                <input type="number" name="ext_milk_fat_pct" value="{{ old('ext_milk_fat_pct', $ext?->milk_fat_pct) }}"
                                    min="0" max="10" step="0.1" class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    {{-- ═══ SECTION PISCICULTURE ═══ --}}
                    @if($check->batch->isAquaculture())
                    @php $ext = $ext ?? $check->extension; @endphp
                    <div class="mt-8 bg-blue-50 border border-blue-200 rounded-[2rem] p-6">
                        <h3 class="text-[10px] font-black uppercase text-blue-800 tracking-widest mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 bg-blue-600 rounded-xl flex items-center justify-center text-white text-sm">🐟</span>
                            {{ __("Qualité de l'Eau — Pisciculture") }}
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {{-- Température --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    {{ __("Température eau (°C)") }}
                                </label>
                                <input type="number" name="ext_water_temp" value="{{ old('ext_water_temp', $ext?->water_temp) }}"
                                    min="0" max="40" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="{{ __('ex: 27.5') }}">
                            </div>
                            {{-- pH --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    {{ __("pH de l'eau") }}
                                    <span class="text-blue-400 ml-1 font-normal normal-case">{{ __("(optimal 6.5 – 8.5)") }}</span>
                                </label>
                                <input type="number" name="ext_water_ph" value="{{ old('ext_water_ph', $ext?->water_ph) }}"
                                    min="0" max="14" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="{{ __('ex: 7.2') }}">
                            </div>
                            {{-- O₂ dissous --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    {{ __("O₂ dissous (ppm)") }}
                                    <span class="text-blue-400 ml-1 font-normal normal-case">{{ __("(optimal > 5)") }}</span>
                                </label>
                                <input type="number" name="ext_water_o2_ppm" value="{{ old('ext_water_o2_ppm', $ext?->water_o2_ppm) }}"
                                    min="0" max="20" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="{{ __('ex: 6.0') }}">
                            </div>
                            {{-- Ammoniaque --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    {{ __("Ammoniaque NH₃ (ppm)") }}
                                    <span class="text-blue-400 ml-1 font-normal normal-case">{{ __("(seuil critique > 1)") }}</span>
                                </label>
                                <input type="number" name="ext_water_ammonia_ppm" value="{{ old('ext_water_ammonia_ppm', $ext?->water_ammonia_ppm) }}"
                                    min="0" max="5" step="0.01"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="{{ __('ex: 0.2') }}">
                            </div>
                            {{-- Biomasse --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    {{ __("Biomasse totale (kg)") }}
                                </label>
                                <input type="number" name="ext_biomass_kg" value="{{ old('ext_biomass_kg', $ext?->biomass_kg) }}"
                                    min="0" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="{{ __('Pesée échantillon') }}">
                            </div>
                            {{-- Survie --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    {{ __("Taux de survie (%)") }}
                                </label>
                                <input type="number" name="ext_survival_rate" value="{{ old('ext_survival_rate', $ext?->survival_rate) }}"
                                    min="0" max="100" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="{{ __('ex: 95.5') }}">
                            </div>
                        </div>

                        {{-- Real-time water quality alerts via JS --}}
                        <div id="water-quality-alerts" class="mt-4 space-y-2 hidden">
                            <p class="text-[8px] font-black uppercase text-blue-700 tracking-widest mb-2">⚠ {{ __("Alertes qualité eau détectées") }}</p>
                            <div id="water-alert-ph" class="hidden bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 text-[9px] font-black text-amber-800">
                                {{ __("pH hors plage optimale (6.5 – 8.5)") }}
                            </div>
                            <div id="water-alert-o2" class="hidden bg-red-50 border border-red-200 rounded-xl px-4 py-2 text-[9px] font-black text-red-800">
                                {{ __("O₂ dissous critique (< 3 ppm) — risque d'asphyxie") }}
                            </div>
                            <div id="water-alert-nh3" class="hidden bg-red-50 border border-red-200 rounded-xl px-4 py-2 text-[9px] font-black text-red-800">
                                {{ __("Ammoniaque critique (> 1 ppm) — risque d'intoxication") }}
                            </div>
                        </div>

                        <script>
                        (function() {
                            const phInput  = document.querySelector('[name="ext_water_ph"]');
                            const o2Input  = document.querySelector('[name="ext_water_o2_ppm"]');
                            const nh3Input = document.querySelector('[name="ext_water_ammonia_ppm"]');
                            const alertBox = document.getElementById('water-quality-alerts');

                            function checkWater() {
                                const ph  = parseFloat(phInput?.value);
                                const o2  = parseFloat(o2Input?.value);
                                const nh3 = parseFloat(nh3Input?.value);

                                const alertPh  = document.getElementById('water-alert-ph');
                                const alertO2  = document.getElementById('water-alert-o2');
                                const alertNh3 = document.getElementById('water-alert-nh3');

                                let hasAlert = false;

                                if (!isNaN(ph) && (ph < 6.5 || ph > 8.5)) { alertPh.classList.remove('hidden'); hasAlert = true; }
                                else alertPh.classList.add('hidden');

                                if (!isNaN(o2) && o2 < 3) { alertO2.classList.remove('hidden'); hasAlert = true; }
                                else alertO2.classList.add('hidden');

                                if (!isNaN(nh3) && nh3 > 1) { alertNh3.classList.remove('hidden'); hasAlert = true; }
                                else alertNh3.classList.add('hidden');

                                alertBox.classList.toggle('hidden', !hasAlert);
                            }

                            [phInput, o2Input, nh3Input].forEach(el => el?.addEventListener('input', checkWater));
                        })();
                        </script>
                    </div>
                    @endif

                    <div class="flex flex-col md:flex-row gap-4 pt-6">
                        <a href="{{ route('batches.show', $check->batch_id) }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-6 rounded-[2rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-widest text-[10px] italic transition no-underline flex items-center justify-center">
                            <i class="fas fa-times mr-2"></i> {{ __("Annuler") }}
                        </a>
                        <button type="submit" id="submit_btn_edit" class="flex-[2] bg-slate-900 text-white font-black py-6 rounded-[2rem] hover:bg-blue-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group">
                            <i class="fas fa-save mr-3 group-hover:scale-110 transition-transform"></i>
                            {{ __("Mettre à jour le pointage") }}
                        </button>
                    </div>
                </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Verrouillé") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none mb-2">
                        {{ __("La permission") }} <span class="text-orange-500">elevage.M</span> {{ __("(Modifier) est requise pour rectifier les relevés journaliers.") }}
                    </p>
                    <p class="text-slate-300 text-[9px] font-black uppercase tracking-widest italic leading-none">{{ __("Contactez votre administrateur si vous pensez que c'est une erreur.") }}</p>
                    <a href="{{ route('batches.show', $check->batch_id) }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-orange-500 transition-all">
                        <i class="fas fa-arrow-left mr-2"></i> {{ __("Retour au Lot") }}
                    </a>
                </div>
            @endcan
        </div>
    </div>

    <script>
        function el(id) { return document.getElementById(id); }

        document.addEventListener('DOMContentLoaded', () => {
            updateEditStats();
        });

        function changeEditVal(id, delta) {
            const input = el(id);
            let newVal = (parseFloat(input.value) || 0) + delta;
            if (newVal < 0) newVal = 0;
            input.value = (id === 'feed_consumed') ? newVal.toFixed(1) : Math.round(newVal);
            updateEditStats();
        }

        function updateEditStats() {
            const currentStockBirds = parseFloat(el('current_stock_birds').value) || 0;
            const originalMortality = parseFloat(el('original_mortality').value) || 0;
            const alertThreshold = parseFloat(el('mortality_alert_threshold').value) || 5;
            const maxAllowed = currentStockBirds + originalMortality;

            let newMortality = parseFloat(el('mortality').value) || 0;

            // Correction silencieuse (pas de alert()) + bannière inline
            if (newMortality > maxAllowed) {
                el('mortality').value = maxAllowed;
                newMortality = maxAllowed;
                el('mortality-error-banner').classList.remove('hidden');
                el('mortality-error-msg').innerText =
                    `{{ __("Mortalité plafonnée à") }} ${maxAllowed} {{ __("(effectif actuel + mortalité originale).") }}`;
            } else {
                el('mortality-error-banner').classList.add('hidden');
            }

            // Taux de mortalité (relatif à l'effectif max disponible)
            const pct = maxAllowed > 0 ? (newMortality / maxAllowed) * 100 : 0;
            el('mortality-pct').innerText = pct.toFixed(2) + '% {{ __("DU LOT") }}';

            // Badge alerte si seuil dépassé
            const badge = el('mortality-alert-badge');
            const card = el('mortality-card');
            if (pct >= alertThreshold) {
                badge.classList.remove('hidden');
                card.classList.add('bg-red-50', 'border-red-200');
                el('mortality-pct').classList.remove('text-slate-300');
                el('mortality-pct').classList.add('text-red-500');
            } else {
                badge.classList.add('hidden');
                card.classList.remove('bg-red-50', 'border-red-200');
                el('mortality-pct').classList.remove('text-red-500');
                el('mortality-pct').classList.add('text-slate-300');
            }

            // Vérification stock aliment
            const select = el('feed_type');
            if (!select || select.selectedIndex === -1) return;

            const consumed = parseFloat(el('feed_consumed').value) || 0;
            const selectedOption = select.options[select.selectedIndex];
            const stockAvailable = parseFloat(selectedOption.getAttribute('data-stock')) || 0;
            const warning = el('stock-warning');
            const warningMsg = el('stock-warning-msg');
            const btn = el('submit_btn_edit');

            if (consumed > stockAvailable) {
                warning.classList.remove('hidden');
                warningMsg.innerText = `⚠️ {{ __("Stock insuffisant (Disponible") }} : ${new Intl.NumberFormat('fr-FR').format(stockAvailable)} kg)`;
                select.classList.add('ring-4', 'ring-red-500');
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                warning.classList.add('hidden');
                select.classList.remove('ring-4', 'ring-red-500');
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }
    </script>
</x-app-layout>
