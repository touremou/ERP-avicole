<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 text-left">
                @php
                    $batchId = $batch->id ?? request()->query('batch_id');
                    $backUrl = $batchId ? route('batches.show', ['batch' => $batchId]) : route('batches.index');
                @endphp
                
                <x-back :to="$backUrl" />
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        📊 {{ __("Pointage de Précision") }}
                    </h2>
                    <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mt-1 italic leading-none">
                        {{ __("Lot :") }} {{ $batch->code ?? __("Chargement...") }} • {{ $batch->building->name ?? __("Mode Terrain") }}
                        <span id="offline-qty-display">({{ $batch->current_quantity ?? '...' }} {{ __("têtes") }})</span>
                    </p>
                </div>
            </div>
            
            <div id="perf-widget" class="hidden md:flex items-center gap-4 bg-slate-900 p-2 pl-4 rounded-2xl border border-slate-700 shadow-2xl transition-all animate-in slide-in-from-right">
                <div class="text-right">
                    <p class="text-[8px] font-black text-slate-500 uppercase leading-none mb-1">{{ __("Ratio Conso.") }}</p>
                    <p class="text-xs font-black text-emerald-400 italic leading-none"><span id="ratio-val">0</span> {{ __("g/sujet") }}</p>
                </div>
                <div class="w-10 h-10 bg-slate-800 rounded-xl flex items-center justify-center text-emerald-400 shadow-inner">
                    <i class="fa-solid fa-bolt-lightning text-xs"></i>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @can('elevage.C')
                @if ($errors->any())
                    <div class="mb-8 p-6 bg-red-600 text-white rounded-[2.5rem] shadow-xl animate-pulse text-left">
                        <p class="text-[10px] font-black uppercase italic mb-2">{{ __("❌ Erreurs de validation détectées :") }}</p>
                        <ul class="list-disc list-inside text-xs font-black uppercase tracking-tight">
                            @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                        </ul>
                    </div>
                @endif

                @php
                    $age = \Carbon\Carbon::parse($batch->arrival_date)->diffInDays(now()) + 1;
                    $todayStep = $batch->protocol ? $batch->protocol->steps->where('day_number', $age)->first() : null;
                @endphp

                @if($todayStep)
                    <div class="mb-8 p-6 bg-gradient-to-br from-indigo-600 to-purple-700 rounded-[2.5rem] text-white shadow-2xl relative overflow-hidden group text-left">
                        <div class="absolute right-0 top-0 opacity-10 translate-x-4 -translate-y-4 group-hover:scale-110 transition-transform duration-700 pointer-events-none">
                            <i class="fa-solid fa-syringe text-[120px]"></i>
                        </div>
                        <div class="flex items-center justify-between relative z-10">
                            <div class="flex items-center gap-5">
                                <div class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center text-2xl shadow-inner">💉</div>
                                <div>
                                    <p class="text-[10px] font-black uppercase opacity-70 leading-none tracking-widest">{{ __("Soin Planifié • Jour :age", ['age' => $age]) }}</p>
                                    <p class="text-xl font-black uppercase italic mt-1 leading-none tracking-tighter">{{ $todayStep->action_name }}</p>
                                </div>
                            </div>
                            <button type="button" onclick="fillTreatment('{{ $todayStep->type }}', '{{ $todayStep->action_name }}')" 
                                    class="px-8 py-3 bg-white text-indigo-700 rounded-xl text-[10px] font-black uppercase hover:bg-emerald-400 hover:text-white transition shadow-xl italic tracking-widest leading-none">
                                {{ __("Appliquer") }}
                            </button>
                        </div>
                    </div>
                @endif

                <form action="{{ route('daily-checks.store') }}" method="POST" class="space-y-8" id="precision-form">
                    @csrf
                    <input type="hidden" name="batch_id" value="{{ $batch->id ?? request()->query('batch_id') }}">
                    <input type="hidden" id="current_stock" value="{{ $batch->current_quantity ?? 0 }}">

                    {{-- 01: Date & État --}}
                    <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 flex flex-wrap md:flex-nowrap items-center justify-between gap-6 text-left">
                        <div class="flex-1">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">Date du relevé</label>
                            <input type="date" name="check_date" value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}" 
                                   class="font-black text-slate-800 border-none outline-none text-2xl bg-transparent focus:text-blue-600 p-0 transition-all cursor-pointer leading-none">
                        </div>
                        <div class="w-full md:w-64">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest leading-none">État Sanitaire Global</label>
                            <select name="health_status" required class="w-full p-4 bg-slate-50 border-none rounded-2xl font-black text-xs uppercase focus:ring-2 focus:ring-blue-500 shadow-inner appearance-none transition-all cursor-pointer italic text-left">
                                <option value="Normal">🟢 Normal (RAS)</option>
                                <option value="Alerte">🟡 Alerte (Surveillance)</option>
                                <option value="Critique">🔴 Critique (Urgence)</option>
                            </select>
                        </div>
                    </div>

                    {{-- 02: Mortalité & Aliment --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-left">
                        <div id="mortality-card" class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 transition-all duration-500 relative">
                            <div class="flex justify-between items-center mb-6">
                                <label class="text-[10px] font-black text-red-500 uppercase tracking-widest italic leading-none">Mortalité (Têtes)</label>
                                <span id="mortality-pct" class="text-[9px] font-black text-slate-300 uppercase italic">0% du lot</span>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <button type="button" onclick="changeVal('mortality', -1)" class="w-14 h-14 shrink-0 rounded-2xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-all font-black text-xl shadow-sm">-</button>
                                <input type="number" name="mortality" id="mortality" value="{{ old('mortality', 0) }}" min="0" oninput="updateStats()" 
                                       class="w-full text-center text-7xl font-black text-slate-800 outline-none bg-transparent border-none focus:ring-0 p-0 appearance-none m-0 leading-none italic">
                                <button type="button" onclick="changeVal('mortality', 1)" class="w-14 h-14 shrink-0 rounded-2xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-all font-black text-xl shadow-sm">+</button>
                            </div>
                        </div>

                        <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 overflow-hidden space-y-6">
                            <div>
                                <label class="block text-[10px] font-black text-blue-500 uppercase mb-4 tracking-widest italic leading-none">Consommation (Kg)</label>
                                <div class="flex items-center justify-between gap-4">
                                    <button type="button" onclick="changeVal('feed_consumed', -1)" class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-all font-black text-lg">-1</button>
                                    <input type="number" name="feed_consumed" id="feed_consumed" value="{{ old('feed_consumed', 0) }}" min="0" step="0.1" oninput="updateStats()"
                                        class="w-full text-center text-5xl font-black text-slate-800 outline-none bg-transparent border-none focus:ring-0 p-0 m-0 leading-none italic">
                                    <button type="button" onclick="changeVal('feed_consumed', 5)" class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-all font-black text-lg">+5</button>
                                </div>
                                @if(($suggestedFeed ?? null) > 0)
                                <button type="button"
                                    onclick="document.getElementById('feed_consumed').value={{ round($suggestedFeed, 1) }}; updateStats();"
                                    title="{{ __('Barème de la souche ajusté à l\'âge et au climat (cf. Recommandation du jour)') }}"
                                    class="mt-3 w-full flex items-center justify-between px-4 py-2 bg-blue-50 hover:bg-blue-100 rounded-xl border border-blue-100 transition cursor-pointer">
                                    <span class="text-[8px] font-black text-blue-500 uppercase tracking-widest italic"><i class="fa-solid fa-brain mr-1"></i>{{ __('Reco. du jour') }}</span>
                                    <span class="text-[10px] font-black text-blue-700 italic">{{ round($suggestedFeed, 1) }} kg</span>
                                </button>
                                @endif
                            </div>

                            <div class="pt-4 border-t border-slate-50">
                                <label class="block text-[9px] font-black text-slate-400 uppercase mb-3 tracking-widest leading-none italic">
                                    Type d'Aliment (Silo : {{ $batch->feedSector() }})
                                </label>
                                <select name="feed_type" id="feed_type" required onchange="checkFeedStock()"
                                        class="w-full p-4 bg-slate-50 border-none rounded-2xl font-black text-[10px] uppercase focus:ring-2 focus:ring-blue-500 shadow-inner italic outline-none appearance-none cursor-pointer">
                                    <option value="">-- CHOISIR L'ALIMENT --</option>
                                    @foreach($phases as $phaseName)
                                        @php
                                            // Utilisation directe du tableau préparé par le Controller (Aucune requête DB)
                                            $availableKg = $stockData[$phaseName] ?? 0;

                                            // Présélection intelligente selon l'âge et le secteur du lot.
                                            $isSelected = $batch->feedPreselectPhase($age) === $phaseName;
                                        @endphp
                                        <option value="{{ $phaseName }}" data-stock="{{ $availableKg }}" {{ $isSelected ? 'selected' : '' }}>
                                            {{ str_replace($batch->feedSector() . ' ', '', $phaseName) }} • (Stock: {{ number_format($availableKg, 1) }} kg)
                                        </option>
                                    @endforeach
                                </select>
                                <div id="stock-warning" class="hidden mt-2 p-3 bg-red-50 rounded-xl border border-red-100">
                                    <p class="text-[8px] text-red-600 font-black uppercase italic leading-none animate-pulse">
                                        ⚠️ Stock insuffisant en magasin !
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 03: Ambiance (air) — sans objet en pisciculture (milieu = eau, cf. section dédiée) --}}
                    @if($batch->tracksAirAmbiance())
                    @php $daysSinceLitter = $batch->days_since_litter_change; @endphp
                    <div x-data="{ litterChanged: {{ old('litter_changed') ? 'true' : 'false' }} }" class="bg-slate-900 p-10 rounded-[4rem] shadow-2xl text-white relative overflow-hidden text-left">
                        <div class="absolute right-0 bottom-0 opacity-10 p-8 scale-150 pointer-events-none"><i class="fas fa-wind text-white"></i></div>
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-[0.3em] leading-none">Paramètres Ambiance</h3>
                            @if($batch->usesLitter())
                            <label class="flex items-center gap-3 bg-white/5 px-5 py-2.5 rounded-2xl cursor-pointer hover:bg-white/10 transition border border-white/10 group">
                                <input type="checkbox" name="litter_changed" value="1" x-model="litterChanged" class="rounded border-none bg-white/20 text-blue-500 focus:ring-0">
                                <span class="text-[9px] font-black uppercase italic tracking-widest text-slate-300 leading-none mt-0.5">Litière Changée</span>
                            </label>
                            @endif
                        </div>

                        @if($batch->usesLitter())
                        {{-- Rappel biosécurité : ancienneté de la litière en place --}}
                        <div @class([
                            'mb-8 px-5 py-3 rounded-2xl border flex items-center gap-3 text-[9px] font-black uppercase tracking-widest leading-none',
                            'bg-amber-500/10 border-amber-500/30 text-amber-300' => $daysSinceLitter !== null && $daysSinceLitter >= 21,
                            'bg-white/5 border-white/10 text-slate-400' => $daysSinceLitter === null || $daysSinceLitter < 21,
                        ])>
                            <i class="fa-solid fa-leaf"></i>
                            @if($daysSinceLitter === null)
                                <span>Aucun renouvellement de litière enregistré sur ce lot</span>
                            @else
                                <span>Litière en place depuis <span class="text-white">{{ $daysSinceLitter }} j</span>@if($daysSinceLitter >= 21) — pensez au renouvellement @endif</span>
                            @endif
                        </div>

                        {{-- Fumier ramassé : valorisé en stock fertilisant à la coche « Litière changée » --}}
                        <div x-show="litterChanged" x-cloak class="mb-8 px-5 py-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30">
                            <label class="block text-[8px] font-black text-emerald-300 uppercase tracking-widest mb-2 leading-none">Fumier ramassé (Kg) — vendable comme fertilisant</label>
                            <input type="number" name="manure_collected_kg" min="0" step="0.1" value="{{ old('manure_collected_kg') }}" placeholder="0"
                                   class="w-full bg-white/5 border border-white/10 p-4 rounded-2xl text-xl text-emerald-300 text-center outline-none italic font-black">
                        </div>
                        @endif

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 relative z-10 italic font-black">
                            <div class="space-y-2 text-center">
                                <label class="block text-[8px] font-black text-slate-500 uppercase tracking-widest leading-none">Eau (L)
                                    @if(($suggestedWater ?? null) > 0)<i class="fa-solid fa-brain text-blue-400 ml-1" title="Reco. du jour disponible (barème souche ajusté au climat)"></i>@endif
                                </label>
                                <input type="number" name="water_consumed" id="water_consumed" min="0" value="{{ old('water_consumed', 0) }}" step="0.1" class="w-full bg-white/5 border border-white/10 p-4 rounded-2xl text-xl text-blue-400 text-center outline-none italic font-black">
                                @if(($suggestedWater ?? null) > 0)
                                <button type="button"
                                    onclick="document.getElementById('water_consumed').value={{ round($suggestedWater, 1) }};"
                                    title="{{ __('Barème de la souche ajusté à l\'âge et au climat (cf. Recommandation du jour)') }}"
                                    class="w-full flex items-center justify-center gap-1 px-2 py-1.5 bg-blue-500/10 hover:bg-blue-500/20 rounded-xl border border-blue-400/20 transition cursor-pointer">
                                    <span class="text-[8px] font-black text-blue-300 uppercase tracking-widest italic"><i class="fa-solid fa-brain mr-1"></i>{{ __('Reco.') }} {{ round($suggestedWater, 1) }} L</span>
                                </button>
                                @endif
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[8px] font-black text-slate-500 uppercase tracking-widest text-center leading-none">Temp. (°C)
                                    @if(($weather['temp_max'] ?? null) !== null)<i class="fa-solid fa-cloud-sun text-sky-400 ml-1" title="Pré-rempli météo ({{ $weather['label'] ?? '' }})"></i>@endif
                                </label>
                                <div class="flex bg-white/5 rounded-2xl border border-white/10 overflow-hidden">
                                    <input type="number" name="temp_min" placeholder="Min" step="0.1" value="{{ old('temp_min', $weather['temp_min'] ?? '') }}" class="w-1/2 bg-transparent border-none p-4 text-cyan-400 text-center text-sm outline-none font-black italic">
                                    <input type="number" name="temp_max" placeholder="Max" step="0.1" value="{{ old('temp_max', $weather['temp_max'] ?? '') }}" class="w-1/2 bg-transparent border-none p-4 text-orange-400 text-center text-sm outline-none border-l border-white/10 font-black italic">
                                </div>
                            </div>
                            <div class="space-y-2 text-center">
                                <label class="block text-[8px] font-black text-slate-500 uppercase tracking-widest leading-none">Humidité (%)
                                    @if(($weather['humidity'] ?? null) !== null)<i class="fa-solid fa-cloud-sun text-sky-400 ml-1" title="Pré-rempli météo ({{ $weather['label'] ?? '' }})"></i>@endif
                                </label>
                                <input type="number" name="humidity" min="0" max="100" step="0.1" value="{{ old('humidity', $weather['humidity'] ?? '') }}" class="w-full bg-white/5 border border-white/10 p-4 rounded-2xl text-xl text-purple-400 text-center outline-none font-black italic">
                            </div>
                            <div class="space-y-2 text-center">
                                <label class="block text-[8px] font-black text-slate-500 uppercase tracking-widest leading-none">Poids moyen / sujet (kg)</label>
                                <input type="number" min="0" name="avg_weight" step="0.001" placeholder="0.000" title="Poids moyen d'un seul animal (moyenne d'un échantillon pesé), pas le poids total du lot." class="w-full bg-white/5 border border-white/10 p-4 rounded-2xl text-xl text-emerald-400 text-center outline-none font-black italic">
                                <p class="text-[7px] font-bold text-slate-500 uppercase tracking-wide leading-tight italic">Moyenne par tête, pas le poids du lot</p>
                            </div>
                        </div>
                    </div>
                    @endif

                    {{-- 04: Soins --}}
                    <div class="bg-white p-10 rounded-[3.5rem] shadow-sm border border-slate-100 text-left">
                        <h3 class="text-[10px] font-black uppercase text-orange-500 mb-8 tracking-[0.2em] flex items-center gap-2">
                            <span class="w-2 h-2 bg-orange-500 rounded-full animate-ping"></span> Soins & Mouvements
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="p-5 bg-orange-50/50 rounded-3xl border border-orange-100">
                                    <label class="block text-[8px] font-black text-orange-400 uppercase mb-2 text-center">Infirmerie (In)</label>
                                    <input type="number" name="qty_quarantine_in" value="0" min="0" class="w-full bg-transparent text-center text-3xl font-black text-orange-600 border-none outline-none italic">
                                </div>
                                <div class="p-5 bg-emerald-50/50 rounded-3xl border border-emerald-100">
                                    <label class="block text-[8px] font-black text-emerald-400 uppercase mb-2 text-center">Rétablis (Out)</label>
                                    <input type="number" name="qty_quarantine_out" value="0" min="0" class="w-full bg-transparent text-center text-3xl font-black text-emerald-600 border-none outline-none italic">
                                </div>
                            </div>
                            {{-- Acte sanitaire : lien vers le registre complet d'interventions --}}
                            <a href="{{ route('health.create', ['batch_id' => $batch->id]) }}"
                               class="flex items-center justify-between gap-3 p-4 bg-rose-50 hover:bg-rose-100 rounded-2xl border border-rose-100 transition-all no-underline group">
                                <div class="flex items-center gap-3">
                                    <span class="w-8 h-8 bg-rose-600 rounded-xl flex items-center justify-center text-white text-xs shrink-0">
                                        <i class="fa-solid fa-heart-pulse"></i>
                                    </span>
                                    <div>
                                        <p class="text-[10px] font-black text-rose-700 uppercase italic leading-none">{{ __("Enregistrer un acte sanitaire") }}</p>
                                        <p class="text-[8px] font-bold text-rose-400 mt-0.5 leading-none">{{ __("Vaccin · Traitement · Vitamine · Désinfection") }}</p>
                                    </div>
                                </div>
                                <i class="fa-solid fa-arrow-right text-rose-300 group-hover:text-rose-600 transition text-xs"></i>
                            </a>
                        </div>
                        {{-- Bien-être animal : adapté à l'espèce (boiterie = volaille + mammifères, picage = volaille). --}}
                        @php $showLame = $batch->tracksLameness(); $showPecking = $batch->tracksPecking(); @endphp
                        @if($showLame || $showPecking)
                        <div class="grid {{ $showLame && $showPecking ? 'grid-cols-2' : 'grid-cols-1' }} gap-4 mb-6">
                            @if($showLame)
                            <div class="p-5 bg-violet-50/60 rounded-3xl border border-violet-100">
                                <label class="block text-[8px] font-black text-violet-500 uppercase mb-2 text-center tracking-widest">Boiteux (Bien-être)</label>
                                <input type="number" name="lame_count" value="{{ old('lame_count', 0) }}" min="0" class="w-full bg-transparent text-center text-3xl font-black text-violet-600 border-none outline-none italic">
                            </div>
                            @endif
                            @if($showPecking)
                            <div class="p-5 bg-fuchsia-50/60 rounded-3xl border border-fuchsia-100">
                                <label class="block text-[8px] font-black text-fuchsia-500 uppercase mb-2 text-center tracking-widest">Picage / Blessés</label>
                                <input type="number" name="pecking_injury_count" value="{{ old('pecking_injury_count', 0) }}" min="0" class="w-full bg-transparent text-center text-3xl font-black text-fuchsia-600 border-none outline-none italic">
                            </div>
                            @endif
                        </div>
                        @endif

                        <textarea name="observations" rows="2" class="w-full bg-slate-50 rounded-[2rem] p-6 outline-none focus:bg-white border-2 border-transparent focus:border-blue-500 font-black text-slate-600 shadow-inner text-xs uppercase italic" placeholder="OBSERVATIONS OU SYMPTÔMES..."></textarea>
                    </div>

                    {{-- ═══ SECTION CROISSANCE / NAISSANCES (Ruminants, Porcins, Lapins) ═══ --}}
                    @if($batch->isGmqTracked())
                    <div class="mt-8 bg-emerald-50 border border-emerald-200 rounded-[2rem] p-6">
                        <h3 class="text-[10px] font-black uppercase text-emerald-800 tracking-widest mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 bg-emerald-600 rounded-xl flex items-center justify-center text-white text-sm">{{ $batch->species?->icon ?? '🐑' }}</span>
                            Suivi Naissances & Croissance
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            {{-- Naissances --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Naissances du jour
                                </label>
                                <input type="number" name="ext_qty_born" value="{{ old('ext_qty_born', 0) }}"
                                    min="0"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            {{-- Sevrages --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Sevrages du jour
                                </label>
                                <input type="number" name="ext_qty_weaned" value="{{ old('ext_qty_weaned', 0) }}"
                                    min="0"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            {{-- Lait (chèvres uniquement) --}}
                            @if($batch->species?->tracks_milk)
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Production lait (litres)
                                </label>
                                <input type="number" name="ext_milk_liters" value="{{ old('ext_milk_liters') }}"
                                    min="0" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Taux Matière Grasse (%)
                                </label>
                                <input type="number" name="ext_milk_fat_pct" value="{{ old('ext_milk_fat_pct') }}"
                                    min="0" max="10" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                            </div>
                            @endif
                        </div>
                        {{-- GMQ info (calculated display) --}}
                        @php
                            $lastCheck = $batch->dailyChecks()->latest('check_date')->first();
                            $prevWeight = $lastCheck?->avg_weight ?? $batch->avg_weight_start;
                            $daysSinceLast = $lastCheck ? now()->diffInDays($lastCheck->check_date) : $batch->age;
                        @endphp
                        @if($prevWeight && $daysSinceLast > 0)
                        <div class="mt-4 p-4 bg-white rounded-2xl border border-emerald-100">
                            <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest mb-1">Dernier poids enregistré</p>
                            <p class="text-sm font-black text-slate-800">{{ number_format($prevWeight, 3) }} kg
                                <span class="text-[8px] text-slate-400 font-normal ml-2">il y a {{ $daysSinceLast }} jour(s)</span>
                            </p>
                            <p class="text-[8px] text-emerald-600 mt-1 uppercase font-black">
                                Saisir le poids moyen aujourd'hui dans le champ "Poids moyen" pour calculer le GMQ automatiquement.
                            </p>
                        </div>
                        @endif
                    </div>
                    @endif

                    {{-- ═══ SECTION PISCICULTURE ═══ --}}
                    @if($batch->isAquaculture())
                    @php
                        // Seuils qualité d'eau pilotés par les paramètres (group « pisciculture »)
                        // au lieu de valeurs codées en dur — éditables dans Paramètres › Pisciculture.
                        $phMin    = (float) setting('pisciculture.ph_min', 6.5);
                        $phMax    = (float) setting('pisciculture.ph_max', 8.5);
                        $o2Alert  = (float) setting('pisciculture.o2_alert', 4);
                        $nh3Alert = (float) setting('pisciculture.ammonia_alert', 1);
                        $tempMin  = (float) setting('pisciculture.temp_min', 25);
                        $tempMax  = (float) setting('pisciculture.temp_max', 32);
                    @endphp
                    <div class="mt-8 bg-blue-50 border border-blue-200 rounded-[2rem] p-6">
                        <h3 class="text-[10px] font-black uppercase text-blue-800 tracking-widest mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 bg-blue-600 rounded-xl flex items-center justify-center text-white text-sm">🐟</span>
                            Qualité de l'Eau — Pisciculture
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {{-- Température --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Température eau (°C)
                                    <span class="text-blue-400 ml-1 font-normal normal-case">(optimal {{ $tempMin }} – {{ $tempMax }})</span>
                                </label>
                                <input type="number" name="ext_water_temp" value="{{ old('ext_water_temp') }}"
                                    min="0" max="40" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="ex: 27.5">
                            </div>
                            {{-- pH --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    pH de l'eau
                                    <span class="text-blue-400 ml-1 font-normal normal-case">(optimal {{ $phMin }} – {{ $phMax }})</span>
                                </label>
                                <input type="number" name="ext_water_ph" value="{{ old('ext_water_ph') }}"
                                    min="0" max="14" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="ex: 7.2">
                            </div>
                            {{-- O₂ dissous --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    O₂ dissous (ppm)
                                    <span class="text-blue-400 ml-1 font-normal normal-case">(alerte < {{ $o2Alert }})</span>
                                </label>
                                <input type="number" name="ext_water_o2_ppm" value="{{ old('ext_water_o2_ppm') }}"
                                    min="0" max="20" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="ex: 6.0">
                            </div>
                            {{-- Ammoniaque --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Ammoniaque NH₃ (ppm)
                                    <span class="text-blue-400 ml-1 font-normal normal-case">(seuil critique > {{ $nh3Alert }})</span>
                                </label>
                                <input type="number" name="ext_water_ammonia_ppm" value="{{ old('ext_water_ammonia_ppm') }}"
                                    min="0" max="5" step="0.01"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="ex: 0.2">
                            </div>
                            {{-- Biomasse --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Biomasse totale (kg)
                                </label>
                                <input type="number" name="ext_biomass_kg" value="{{ old('ext_biomass_kg') }}"
                                    min="0" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="Pesée échantillon">
                            </div>
                            {{-- Survie --}}
                            <div>
                                <label class="block text-[9px] font-black uppercase text-slate-500 tracking-widest mb-2">
                                    Taux de survie (%)
                                </label>
                                <input type="number" name="ext_survival_rate" value="{{ old('ext_survival_rate') }}"
                                    min="0" max="100" step="0.1"
                                    class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 text-sm font-black text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                                    placeholder="ex: 95.5">
                            </div>
                        </div>

                        {{-- Real-time water quality alerts via JS --}}
                        <div id="water-quality-alerts" class="mt-4 space-y-2 hidden">
                            <p class="text-[8px] font-black uppercase text-blue-700 tracking-widest mb-2">⚠ Alertes qualité eau détectées</p>
                            <div id="water-alert-temp" class="hidden bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 text-[9px] font-black text-amber-800">
                                Température hors plage optimale ({{ $tempMin }} – {{ $tempMax }} °C)
                            </div>
                            <div id="water-alert-ph" class="hidden bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 text-[9px] font-black text-amber-800">
                                pH hors plage optimale ({{ $phMin }} – {{ $phMax }})
                            </div>
                            <div id="water-alert-o2" class="hidden bg-red-50 border border-red-200 rounded-xl px-4 py-2 text-[9px] font-black text-red-800">
                                O₂ dissous critique (< {{ $o2Alert }} ppm) — risque d'asphyxie
                            </div>
                            <div id="water-alert-nh3" class="hidden bg-red-50 border border-red-200 rounded-xl px-4 py-2 text-[9px] font-black text-red-800">
                                Ammoniaque critique (> {{ $nh3Alert }} ppm) — risque d'intoxication
                            </div>
                        </div>

                        <script>
                        (function() {
                            // Seuils injectés depuis les paramètres (group « pisciculture »).
                            const PH_MIN = {{ $phMin }}, PH_MAX = {{ $phMax }}, O2_MIN = {{ $o2Alert }},
                                  NH3_MAX = {{ $nh3Alert }}, TEMP_MIN = {{ $tempMin }}, TEMP_MAX = {{ $tempMax }};

                            const tempInput = document.querySelector('[name="ext_water_temp"]');
                            const phInput  = document.querySelector('[name="ext_water_ph"]');
                            const o2Input  = document.querySelector('[name="ext_water_o2_ppm"]');
                            const nh3Input = document.querySelector('[name="ext_water_ammonia_ppm"]');
                            const alertBox = document.getElementById('water-quality-alerts');

                            function checkWater() {
                                const temp = parseFloat(tempInput?.value);
                                const ph  = parseFloat(phInput?.value);
                                const o2  = parseFloat(o2Input?.value);
                                const nh3 = parseFloat(nh3Input?.value);

                                const alertTemp = document.getElementById('water-alert-temp');
                                const alertPh  = document.getElementById('water-alert-ph');
                                const alertO2  = document.getElementById('water-alert-o2');
                                const alertNh3 = document.getElementById('water-alert-nh3');

                                let hasAlert = false;

                                if (!isNaN(temp) && (temp < TEMP_MIN || temp > TEMP_MAX)) { alertTemp.classList.remove('hidden'); hasAlert = true; }
                                else alertTemp.classList.add('hidden');

                                if (!isNaN(ph) && (ph < PH_MIN || ph > PH_MAX)) { alertPh.classList.remove('hidden'); hasAlert = true; }
                                else alertPh.classList.add('hidden');

                                if (!isNaN(o2) && o2 < O2_MIN) { alertO2.classList.remove('hidden'); hasAlert = true; }
                                else alertO2.classList.add('hidden');

                                if (!isNaN(nh3) && nh3 > NH3_MAX) { alertNh3.classList.remove('hidden'); hasAlert = true; }
                                else alertNh3.classList.add('hidden');

                                alertBox.classList.toggle('hidden', !hasAlert);
                            }

                            [tempInput, phInput, o2Input, nh3Input].forEach(el => el?.addEventListener('input', checkWater));
                        })();
                        </script>
                    </div>
                    @endif

                    <div class="flex flex-col md:flex-row gap-4 pt-6">
                        <a href="{{ $backUrl }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-6 rounded-[2rem] shadow-sm hover:bg-slate-50 text-center uppercase tracking-widest text-[10px] italic no-underline flex items-center justify-center">
                            <i class="fas fa-times mr-2"></i> Annuler
                        </a>
                        <button type="submit" id="submit_btn" class="flex-[2] bg-slate-900 text-white font-black py-6 rounded-[2rem] hover:bg-blue-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group">
                            Confirmer le pointage
                        </button>
                    </div>
                </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">Accès Verrouillé</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none mb-2">
                        La permission <span class="text-blue-500">elevage.C</span> (Créer) est requise pour saisir des relevés journaliers.
                    </p>
                    <p class="text-slate-300 text-[9px] font-black uppercase tracking-widest italic leading-none">Contactez votre administrateur si vous pensez que c'est une erreur.</p>
                    <a href="{{ $backUrl }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-600 transition-all">
                        <i class="fas fa-arrow-left mr-2"></i> Retour au Lot
                    </a>
                </div>
            @endcan
        </div>
    </div>

    <script>
        function changeVal(id, delta) {
            const input = document.getElementById(id);
            let newVal = (parseFloat(input.value) || 0) + delta;
            if (newVal < 0) newVal = 0;
            
            const maxStock = parseFloat(document.getElementById('current_stock').value);
            if (id === 'mortality' && newVal > maxStock) newVal = maxStock;

            input.value = (id === 'feed_consumed') ? newVal.toFixed(1) : Math.round(newVal);
            updateStats();
        }

        function fillTreatment(type, name) {
            document.getElementById('t_type').value = type;
            document.getElementById('t_name').value = name;
            document.getElementById('t_type').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function checkFeedStock() {
            const select = document.getElementById('feed_type');
            if(select.selectedIndex === -1) return;
            const selectedOption = select.options[select.selectedIndex];
            const stockAvailable = parseFloat(selectedOption.getAttribute('data-stock')) || 0;
            const consumed = parseFloat(document.getElementById('feed_consumed').value) || 0;
            const warning = document.getElementById('stock-warning');
            const btn = document.getElementById('submit_btn');

            if (consumed > stockAvailable) {
                warning.classList.remove('hidden');
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                warning.classList.add('hidden');
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }

        function updateStats() {
            const stock = parseFloat(document.getElementById('current_stock').value);
            const mortality = parseFloat(document.getElementById('mortality').value) || 0;
            const feed = parseFloat(document.getElementById('feed_consumed').value) || 0;
            
            const pct = stock > 0 ? (mortality / stock) * 100 : 0;
            const mortalityCard = document.getElementById('mortality-card');
            const mortalityPctSpan = document.getElementById('mortality-pct');
            
            mortalityPctSpan.innerText = pct.toFixed(2) + '% DU LOT';
            if(pct >= 1.0) {
                mortalityCard.classList.add('bg-red-50', 'border-red-200');
            } else {
                mortalityCard.classList.remove('bg-red-50', 'border-red-200');
            }

            const livingBirds = stock - mortality;
            if(feed > 0 && livingBirds > 0) {
                const ratio = (feed * 1000) / livingBirds;
                document.getElementById('perf-widget').classList.remove('hidden');
                document.getElementById('ratio-val').innerText = Math.round(ratio);
            } else {
                document.getElementById('perf-widget').classList.add('hidden');
            }
            checkFeedStock();
        }

        window.addEventListener('load', async () => {
            const isOffline = {{ ($offline_mode ?? false) ? 'true' : 'false' }};
            const feedSelect = document.getElementById('feed_type');
            
            try {
                if(typeof db !== 'undefined') {
                    const localFeeds = await db.stocks.where('category').equals('conso').toArray();
                    Array.from(feedSelect.options).forEach(option => {
                        if (option.value === "") return;
                        // On cherche d'abord la correspondance stricte (feed_type), sinon on se rabat sur l'ancien (item_name)
                        const match = localFeeds.find(f => f.feed_type === option.value || f.item_name === option.value);
                        if (match) {
                            option.dataset.stock = match.current_quantity;
                            option.text = `${option.text.split(' • ')[0]} • (Stock: ${new Intl.NumberFormat('fr-FR').format(match.current_quantity)} kg)`;
                        }
                    });
                }
            } catch (err) {
                console.warn("IndexedDB non disponible pour sync locale.");
            }

            if (isOffline && typeof db !== 'undefined') {
                const urlParams = new URLSearchParams(window.location.search);
                const batchId = urlParams.get('batch_id');

                if (batchId) {
                    const localBatch = await db.batches.where('id').equals(parseInt(batchId)).first();
                    if (localBatch) {
                        const batchTitle = document.querySelector('h2 + p');
                        batchTitle.innerHTML = `Lot : ${localBatch.code} • MODE TERRAIN`;
                        document.getElementById('current_stock').value = localBatch.current_quantity;
                        
                        const building = await db.buildings.get(localBatch.building_id);
                        if (building) {
                            batchTitle.innerHTML += ` • ${building.name}`;
                        }
                    }
                }
            }
        });

        document.getElementById('precision-form')?.addEventListener('submit', async function(e) {
            if (!navigator.onLine || {{ config('app.database_down', false) ? 'true' : 'false' }}) {
                e.preventDefault();
                if(typeof db === 'undefined') {
                    alert('Erreur: Base locale non initialisée');
                    return;
                }

                const formData = new FormData(this);
                const data = Object.fromEntries(formData.entries());

                data.uuid = self.crypto.randomUUID();
                data.is_synced = 0;
                data.created_at = new Date().toISOString();
                data.mortality = parseInt(data.mortality) || 0;
                data.feed_consumed = parseFloat(data.feed_consumed) || 0;

                try {
                    await db.daily_checks.add(data);

                    // Récupère les stocks de consommation et filtre dynamiquement
                    const allConso = await db.stocks.where('category').equals('conso').toArray();
                    const feedItem = allConso.find(s => s.feed_type === data.feed_type || s.item_name === data.feed_type);
                    if (feedItem) {
                        const newQty = feedItem.current_quantity - data.feed_consumed;
                        await db.stocks.update(feedItem.id, { current_quantity: newQty });
                    }

                    alert("✅ POINTAGE ENREGISTRÉ (MODE TERRAIN)");
                    window.location.href = "{{ route('batches.index') }}";
                } catch (err) {
                    console.error(err);
                    alert("Erreur locale lors de la sauvegarde.");
                }
            }
        });
        
        window.onload = updateStats;
    </script>
</x-app-layout>