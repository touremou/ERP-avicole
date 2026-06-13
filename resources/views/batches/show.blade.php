<x-app-layout>
    @php
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        // Accessors du model (PAS de requêtes SQL ici)
        $batchAge = $batch->age;
        $currentWeek = ceil($batchAge / 7);

        // Stats de Ponte (une seule requête via collection eager-loaded)
        $prodToday = $batch->eggProductions->where('production_date', $today)->first();
        $prodYesterday = $batch->eggProductions->where('production_date', $yesterday)->first();
        $rateToday = $prodToday ? $prodToday->laying_rate : 0;
        $rateYesterday = $prodYesterday ? $prodYesterday->laying_rate : 0;
        $diffPonte = $rateToday - $rateYesterday;

        // Mortalité — utilise les accessors du model (source de vérité)
        $totalMortality = $batch->total_mortality;
        $mortalityRate = $batch->mortality_rate;
        
        // Mortalité du jour (depuis la collection eager-loaded, PAS une nouvelle requête)
        $mortToday = $batch->dailyChecks->where('check_date', $today)->sum('mortality');
        $mortYesterday = $batch->dailyChecks->where('check_date', $yesterday)->sum('mortality');
        $diffMort = $mortToday - $mortYesterday;

        // Type et indicateurs
        $type = strtolower($batch->type);
        $isChair = in_array($type, ['chair', 'poulet de chair']);
        
        // Poids (depuis la collection eager-loaded)
        $sortedChecks = $batch->dailyChecks->sortByDesc('check_date');
        $lastCheck = $sortedChecks->first();
        $prevCheck = $sortedChecks->skip(1)->first();
        $currentWeight = $lastCheck ? ($lastCheck->avg_weight * 1000) : 0; 
        $prevWeight = $prevCheck ? ($prevCheck->avg_weight * 1000) : 0;
        $weightGain = ($currentWeight > 0 && $prevWeight > 0) ? ($currentWeight - $prevWeight) : 0;

        // Normes (une seule requête, acceptable)
        $norm = \App\Models\ProductionNorm::where('batch_type', $batch->type)
                    ->where('week_number', $currentWeek)
                    ->where('model_name', $batch->model_name)
                    ->first();

        $currentPhase = $batch->current_phase;
        $targetWeight = $norm->target_weight ?? 0;
        $targetLayingRate = $norm->target_laying_rate ?? 0;

        // Ovins (pas de norme ProductionNorm) : cible de poids = poids de vente Tabaski.
        $tabaskiTarget = null;
        if ($targetWeight <= 0 && $batch->species?->slug === 'mouton') {
            $tabaskiTarget = (float) setting('elevage.tabaski_target_weight', 35) * 1000; // kg -> g
            $targetWeight = $tabaskiTarget;
        }

        $performanceWeight = ($targetWeight > 0 && $currentWeight > 0) ? ($currentWeight / $targetWeight) * 100 : 100;

        // FCR — utilise l'accessor du model + cibles pilotées par les paramètres
        // (provenderie.fc_target_chair/ponte selon le type, fc_alert = seuil rouge).
        $fcr = $batch->fcr;
        $fcrTarget = (float) (in_array($batch->type, ['ponte', 'reproducteur'])
            ? setting('provenderie.fc_target_ponte', 2.3)
            : setting('provenderie.fc_target_chair', 1.8));
        $fcrAlert  = (float) setting('provenderie.fc_alert', 2.5);
        $fcrBad    = $fcr > 0 && $fcr > $fcrAlert;

        // Effectif vivant — SOURCE DE VÉRITÉ = current_quantity
        // Plus de calcul initial_quantity - totalMortality (qui ignorait quarantaines/tris)
        $currentEffectif = $batch->current_quantity;
        
        // Aliment total (depuis la collection eager-loaded)
        $totalFeed = $batch->dailyChecks->sum('feed_consumed');
        
        // Species-specific
        $isRuminant    = $batch->isRuminant();
        $isAquaculture = $batch->isAquaculture();
        $isGmqTracked  = $batch->isGmqTracked();
        $isVolaille    = $batch->isVolaille();

        // Cible GMQ (g/j) selon l'espèce.
        $gmqTarget = match ($batch->species?->slug) {
            'chevre' => (float) setting('elevage.gmq_cible_caprin', 100),
            'mouton' => (float) setting('elevage.gmq_cible_ovin', 120),
            default  => (float) setting('elevage.gmq_cible_ovin', 120),
        };

        // Suivi de la ponte : piloté par le type de production de l'espèce.
        $showPonte = $batch->tracksEggs();

        $colCount = 3 + ($showPonte ? 1 : 0) + ($isChair ? 1 : 0);
    @endphp

    <x-slot name="header">
        {{-- ALERTES GLOBALES --}}
        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-600 rounded-2xl shadow-sm animate-pulse text-left italic">
                <p class="text-[10px] font-black text-red-400 uppercase tracking-widest italic leading-none mb-2">{{ __("Alerte Système") }}</p>
                <ul class="list-disc pl-5 text-sm font-bold text-red-700">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('success'))
            <div class="mb-6 p-4 bg-emerald-50 border-l-4 border-emerald-600 rounded-2xl shadow-sm text-left italic">
                <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest italic leading-none mb-1">{{ __("Opération réussie") }}</p>
                <p class="text-sm font-bold text-emerald-700">{{ session('success') }}</p>
            </div>
        @endif

        <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-6 md:gap-8 w-full">

    {{-- ZONE 1 : IDENTITÉ & NAVIGATION --}}
    <div class="flex items-center gap-4 md:gap-5 text-left w-full xl:w-auto">
        <a href="{{ route('batches.index') }}"
           class="flex-shrink-0 flex items-center justify-center w-10 h-10 md:w-12 md:h-12 bg-white border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-100 rounded-xl md:rounded-2xl transition-all shadow-sm group no-underline">
            <i class="fa-solid fa-arrow-left text-xs md:text-sm group-hover:-translate-x-1 transition-transform"></i>
        </a>

        <div class="space-y-1 sm:space-y-2 min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 md:gap-3">
                <h2 class="text-2xl md:text-3xl font-black text-slate-900 uppercase italic tracking-tighter leading-none truncate">
                    {{ $batch->code }}
                </h2>
                <div @class([
                    'flex items-center gap-1.5 px-3 py-1 rounded-full text-[8px] md:text-[9px] font-black uppercase tracking-widest italic shadow-sm shrink-0',
                    'bg-emerald-500 text-white' => $batch->status === 'Actif',
                    'bg-slate-200 text-slate-600' => $batch->status !== 'Actif'
                ])>
                    @if($batch->status === 'Actif')
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
                        </span>
                    @endif
                    {{ $batch->status }}
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[9px] md:text-[10px] font-black uppercase tracking-widest italic text-slate-400">
                <span class="text-blue-500 whitespace-nowrap"><i class="fa-solid fa-location-dot mr-1"></i> {{ $batch->building->name }}</span>
                <span class="text-slate-200 hidden sm:inline">|</span>
                <span class="bg-slate-100 px-2 py-0.5 rounded text-slate-500 whitespace-nowrap">{{ $batch->type }}</span>
                <span class="text-slate-200 hidden sm:inline">|</span>
                <span class="text-rose-500 whitespace-nowrap"><i class="fa-solid fa-dna mr-1"></i> {{ $batch->production_phase ?? __("Phase Initiale") }}</span>
            </div>
        </div>
    </div>

    {{-- ZONE 2 & 3 : ACTIONS --}}
    <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-4 w-full xl:w-auto">
        @if($batch->status === 'Actif')

            {{-- Actions Administratives (Grisées) --}}
            <div class="flex items-center justify-between sm:justify-start bg-white p-1.5 rounded-2xl md:rounded-[1.5rem] border border-slate-200 shadow-sm w-full lg:w-auto overflow-x-auto hide-scrollbar shrink-0">
                @can('elevage.M')
                <a href="{{ route('batches.edit', $batch->id) }}"
                   class="p-3 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all shrink-0"
                   title="{{ __("Modifier les paramètres") }}">
                    <i class="fa-solid fa-gear"></i>
                </a>

                <div class="w-px h-6 bg-slate-200 mx-1 shrink-0"></div>

                <button onclick="document.getElementById('modal-transfer').classList.remove('hidden')"
                        class="flex flex-1 sm:flex-none items-center justify-center gap-2 px-4 md:px-5 py-3 bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white rounded-xl font-black text-[9px] md:text-[10px] uppercase tracking-widest transition-all italic border-none cursor-pointer shrink-0">
                    <i class="fa-solid fa-right-left"></i> {{ __("Mutation") }}
                </button>

                <a href="{{ route('batches.close_form', $batch->id) }}"
                   class="flex flex-1 sm:flex-none items-center justify-center gap-2 px-4 md:px-5 py-3 text-slate-400 hover:text-orange-600 hover:bg-orange-50 rounded-xl font-black text-[9px] md:text-[10px] uppercase tracking-widest transition-all italic no-underline shrink-0">
                    <i class="fa-solid fa-flag-checkered"></i> {{ __("Clôture") }}
                </a>
                @endcan
            </div>

            {{-- Actions Quotidiennes (En Grille sur Mobile, Flex sur PC) --}}
            <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 md:gap-3 w-full lg:w-auto">
                @can('elevage.C')

                <button type="button" onclick="event.stopPropagation(); openFeedModal()"
                        class="flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 px-3 py-3 md:px-6 md:py-4 bg-slate-900 text-white rounded-xl md:rounded-2xl font-black text-[9px] md:text-[10px] uppercase italic hover:bg-orange-500 transition-all shadow-lg border-none cursor-pointer group text-center sm:text-left">
                    <i class="fa-solid fa-truck-ramp-box text-orange-400 group-hover:text-white transition-colors text-lg sm:text-base"></i>
                    <span>{{ __("Achat direct") }}</span>
                </button>

                <a href="{{ route('daily-checks.create', ['batch_id' => $batch->id]) }}"
                   class="flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 px-3 py-3 md:px-6 md:py-4 bg-blue-600 text-white rounded-xl md:rounded-2xl font-black text-[9px] md:text-[10px] uppercase italic hover:bg-blue-500 transition-all shadow-lg no-underline text-center sm:text-left">
                    <i class="fa-solid fa-clipboard-check text-blue-200 text-lg sm:text-base"></i>
                    <span>{{ __("Suivi") }}</span>
                </a>

                <a href="{{ route('health.create', ['batch_id' => $batch->id]) }}"
                   class="flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 px-3 py-3 md:px-6 md:py-4 bg-rose-600 text-white rounded-xl md:rounded-2xl font-black text-[9px] md:text-[10px] uppercase italic hover:bg-rose-500 transition-all shadow-lg no-underline text-center sm:text-left">
                    <i class="fa-solid fa-heart-pulse text-rose-200 text-lg sm:text-base"></i>
                    <span>{{ __("Santé") }}</span>
                </a>

                @if($showPonte)
                <a href="{{ route('egg-productions.create', ['batch_id' => $batch->id]) }}"
                   class="flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 px-3 py-3 md:px-6 md:py-4 bg-emerald-500 text-white rounded-xl md:rounded-2xl font-black text-[9px] md:text-[10px] uppercase italic hover:bg-emerald-400 transition-all shadow-lg no-underline text-center sm:text-left">
                    <i class="fa-solid fa-egg text-emerald-200 text-lg sm:text-base"></i>
                    <span>{{ __("Collecte") }}</span>
                </a>
                @endif

                @endcan
            </div>

        @endif
    </div>
</div>
    </x-slot>

    {{-- INDICATEURS EN HAUT --}}
    <div class="grid grid-cols-2 md:grid-cols-{{ $colCount }} gap-4 mb-8 -mt-6 relative z-20 px-4 lg:px-0 font-bold italic">
        @if($showPonte)
        <div class="bg-white p-5 rounded-[2rem] shadow-xl shadow-emerald-500/5 border border-emerald-50 flex items-center gap-4 group transition-transform hover:scale-[1.02]">
            <div class="w-12 h-12 bg-emerald-500 rounded-2xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-egg text-lg group-hover:animate-bounce"></i></div>
            <div class="text-left leading-none">
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 italic">{{ __("Ponte") }}</p>
                <div class="flex items-baseline gap-1">
                    <h4 class="text-xl font-black text-slate-800 tracking-tighter">{{ number_format($rateToday, 1) }}%</h4>
                    <span class="text-[9px] font-black {{ $diffPonte >= 0 ? 'text-emerald-500' : 'text-red-500' }}">{{ $diffPonte >= 0 ? '↑' : '↓' }}{{ abs(number_format($diffPonte, 1)) }}</span>
                </div>
                @if($targetLayingRate > 0)
                <p class="text-[7px] font-black text-slate-300 mt-1 uppercase italic tracking-tighter">{{ __("Cible") }}: {{ $targetLayingRate }}%</p>
                @endif
            </div>
        </div>
        @endif

        <div class="bg-white p-5 rounded-[2rem] shadow-xl shadow-blue-500/5 border border-blue-50 flex flex-col justify-center group transition-transform hover:scale-[1.02]">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-500 rounded-2xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-weight-scale text-lg"></i></div>
                <div class="text-left leading-none">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 italic">{{ __("Poids Moyen") }}</p>
                    <div class="flex items-baseline gap-1">
                        <h4 class="text-xl font-black text-slate-800 tracking-tighter">{{ number_format($currentWeight, 0) }}g</h4>
                        @if($weightGain > 0)<span class="text-[9px] font-black text-emerald-500">+{{ $weightGain }}g</span>@endif
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 mt-3 px-1">
                <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                    <div @class([
                        'h-full transition-all duration-1000',
                        'bg-emerald-500' => $performanceWeight >= 95,
                        'bg-orange-500' => $performanceWeight < 95 && $performanceWeight >= 85,
                        'bg-red-500' => $performanceWeight < 85,
                    ]) style="width: {{ min($performanceWeight, 100) }}%"></div>
                </div>
                <span class="text-[7px] font-black uppercase text-slate-400">
                    @if($tabaskiTarget)
                        {{ __("Cible Tabaski") }} ({{ number_format($tabaskiTarget / 1000, 0) }}kg) : {{ number_format($performanceWeight, 0) }}%
                    @else
                        {{ __("Norme") }}: {{ number_format($performanceWeight, 0) }}%
                    @endif
                </span>
            </div>
        </div>

        @if($isChair)
        <div @class(['p-5 rounded-[2rem] shadow-xl border flex items-center gap-4 group transition-transform hover:scale-[1.02]', 'bg-white border-orange-50' => ! $fcrBad, 'bg-red-50 border-red-100 animate-pulse' => $fcrBad])>
            <div @class(['w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg', 'bg-orange-500' => ! $fcrBad, 'bg-red-600' => $fcrBad])><i class="fa-solid fa-chart-pie text-lg"></i></div>
            <div class="text-left leading-none">
                <p @class(['text-[8px] font-black uppercase tracking-widest mb-1 italic', 'text-slate-400' => ! $fcrBad, 'text-red-400' => $fcrBad])>{{ __("Ratio (IC)") }} <span class="opacity-60">/ {{ __("cible") }} {{ number_format($fcrTarget, 1) }}</span></p>
                <h4 @class(['text-xl font-black tracking-tighter', 'text-slate-800' => ! $fcrBad, 'text-red-700' => $fcrBad])>{{ number_format($fcr, 2) }}</h4>
            </div>
        </div>
        @endif

        <div class="bg-white p-5 rounded-[2rem] shadow-xl shadow-red-500/5 border border-red-50 flex items-center gap-4 group transition-transform hover:scale-[1.02]">
            <div class="w-12 h-12 bg-red-600 rounded-2xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-skull-crossbones text-lg group-hover:rotate-12 transition-transform"></i></div>
            <div class="text-left leading-none">
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 italic">{{ __("Pertes Lot") }}</p>
                <div class="flex items-baseline gap-1">
                    <h4 class="text-xl font-black text-slate-800 tracking-tighter">{{ $totalMortality }}</h4>
                    <span @class(['text-[9px] font-black', 'text-red-500' => $diffMort > 0, 'text-emerald-500' => $diffMort < 0, 'text-slate-300' => $diffMort == 0])>
                        @if($diffMort != 0){!! $diffMort > 0 ? '↑' : '↓' !!}{{ abs($diffMort) }}@else=@endif
                    </span>
                </div>
                <p class="text-[7px] font-black text-red-400 uppercase mt-1">{{ __("Taux") }} : {{ number_format($mortalityRate, 1) }}%</p>
            </div>
        </div>

        <div class="bg-slate-900 p-5 rounded-[2rem] shadow-xl flex items-center gap-4 group transition-transform hover:scale-[1.02]">
            <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-blue-400 border border-white/5 shadow-inner"><i class="fa-solid fa-calendar-check text-lg"></i></div>
            <div class="text-left leading-none">
                <p class="text-[8px] font-black text-slate-500 uppercase tracking-widest mb-1 italic">{{ $currentPhase }}</p>
                <h4 class="text-xl font-black text-white tracking-tighter">J-{{ number_format($batchAge, 0) }} <span class="text-[9px] text-blue-400 font-black ml-1">S-{{ $currentWeek }}</span></h4>
            </div>
        </div>

        @if($isGmqTracked && isset($stats['gmq']))
        <div class="bg-white p-5 rounded-[2rem] shadow-xl border border-emerald-50 flex items-center gap-4 group transition-transform hover:scale-[1.02]">
            <div class="w-12 h-12 bg-emerald-600 rounded-2xl flex items-center justify-center text-white shadow-lg">
                <i class="fa-solid fa-arrow-trend-up text-lg"></i>
            </div>
            <div class="text-left leading-none">
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 italic">{{ __("GMQ") }}</p>
                @if($stats['gmq'] !== null)
                <h4 @class(['text-xl font-black tracking-tighter',
                    'text-emerald-600' => $stats['gmq'] >= $gmqTarget,
                    'text-amber-600'   => $stats['gmq'] >= $gmqTarget * 0.6 && $stats['gmq'] < $gmqTarget,
                    'text-rose-600'    => $stats['gmq'] < $gmqTarget * 0.6])>
                    {{ number_format($stats['gmq'], 0) }} <small class="text-xs opacity-50">g/j</small>
                </h4>
                @else
                <h4 class="text-sm font-black text-slate-300 uppercase">—</h4>
                @endif
                <p class="text-[7px] text-slate-400 mt-1 uppercase font-black">{{ __("Gain Moyen Quotidien") }} <span class="opacity-60">/ {{ __("cible") }} {{ number_format($gmqTarget, 0) }}g</span></p>
            </div>
        </div>
        @endif
    </div>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- BARRE DE CYCLE --}}
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-8 relative overflow-hidden">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest italic flex items-center gap-2 leading-none"><i class="fas fa-arrows-spin text-blue-500"></i> {{ __("Avancement du Cycle de Vie") }}</h3>
                    <span class="text-[10px] font-black text-slate-800 uppercase italic">{{ __("Phase") }} : {{ $currentPhase }}</span>
                </div>
                <div class="w-full h-3 bg-slate-100 rounded-full overflow-hidden flex shadow-inner">
                    @php
                        $maxDays = $batch->productionType?->cycle_days_default ?? ($isChair ? 45 : 540);
                        $progress = min(($batchAge / $maxDays) * 100, 100);
                    @endphp
                    <div class="h-full bg-blue-600 transition-all duration-1000 shadow-lg" style="width: {{ $progress }}%"></div>
                </div>
                <div class="flex justify-between mt-2 text-[8px] font-black text-slate-400 uppercase">
                    <span>{{ __("Arrivée") }}</span>
                    <span>{{ __("Sortie Estimée") }} (J-{{ $maxDays }})</span>
                </div>
            </div>

            {{-- AQUACULTURE: QUALITÉ DE L'EAU --}}
            @if($isAquaculture && (isset($stats['last_water_ph']) || isset($stats['last_water_o2'])))
            <div class="mb-8 bg-blue-50 border border-blue-200 rounded-[2rem] p-6">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-[10px] font-black uppercase text-blue-800 tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-droplet text-blue-500"></i> {{ __("Qualité de l'Eau — Dernier Relevé") }}
                    </h4>
                    @if(count($stats['water_alerts'] ?? []) > 0)
                    <span class="text-[8px] font-black bg-red-600 text-white px-3 py-1 rounded-xl uppercase animate-pulse">
                        {{ count($stats['water_alerts']) }} {{ __("Alerte(s)") }}
                    </span>
                    @endif
                </div>

                {{-- Alerts --}}
                @foreach($stats['water_alerts'] ?? [] as $alert)
                <div @class(['mb-3 px-4 py-3 rounded-xl text-[9px] font-black uppercase',
                    'bg-red-100 text-red-800 border border-red-200' => $alert['level'] === 'critical',
                    'bg-amber-100 text-amber-800 border border-amber-200' => $alert['level'] === 'warning'])>
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i>{{ $alert['message'] }}
                </div>
                @endforeach

                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    @foreach([
                        ['label' => 'Température', 'value' => $stats['last_water_temp'], 'unit' => '°C', 'icon' => 'fa-thermometer-half', 'color' => 'blue'],
                        ['label' => 'pH', 'value' => $stats['last_water_ph'], 'unit' => '', 'icon' => 'fa-flask', 'color' => ($stats['last_water_ph'] ?? 7) < 6.5 || ($stats['last_water_ph'] ?? 7) > 8.5 ? 'red' : 'blue'],
                        ['label' => 'O₂ dissous', 'value' => $stats['last_water_o2'], 'unit' => 'ppm', 'icon' => 'fa-wind', 'color' => ($stats['last_water_o2'] ?? 6) < 3 ? 'red' : (($stats['last_water_o2'] ?? 6) < 5 ? 'amber' : 'blue')],
                        ['label' => 'NH₃', 'value' => $stats['last_water_ammonia'], 'unit' => 'ppm', 'icon' => 'fa-circle-radiation', 'color' => ($stats['last_water_ammonia'] ?? 0) > 1 ? 'red' : (($stats['last_water_ammonia'] ?? 0) > 0.5 ? 'amber' : 'blue')],
                        ['label' => 'Biomasse', 'value' => $stats['last_biomass'], 'unit' => 'kg', 'icon' => 'fa-weight-scale', 'color' => 'blue'],
                        ['label' => 'Survie', 'value' => $stats['last_survival_rate'], 'unit' => '%', 'icon' => 'fa-fish', 'color' => 'blue'],
                    ] as $metric)
                    @if($metric['value'] !== null)
                    <div class="bg-white rounded-2xl p-4 border border-blue-100 text-center">
                        <i class="fa-solid {{ $metric['icon'] }} text-{{ $metric['color'] }}-500 mb-2 text-base"></i>
                        <p class="text-lg font-black text-slate-800">{{ number_format((float)$metric['value'], $metric['unit'] === 'ppm' ? 2 : 1) }}</p>
                        <p class="text-[7px] font-black uppercase text-slate-400 tracking-widest">{{ __($metric['label']) }}{{ $metric['unit'] ? ' ('.$metric['unit'].')' : '' }}</p>
                    </div>
                    @endif
                    @endforeach
                </div>
            </div>
            @endif

            {{-- NAISSANCES & SEVRAGES (ruminants, porcins, lapins) --}}
            @if($isGmqTracked && (($stats['total_born'] ?? 0) > 0 || ($stats['total_weaned'] ?? 0) > 0))
            <div class="mb-8 bg-emerald-50 border border-emerald-200 rounded-[2rem] p-6 flex flex-wrap gap-8 items-center">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-emerald-600 rounded-xl flex items-center justify-center text-white text-lg">🐣</div>
                    <div>
                        <p class="text-2xl font-black text-emerald-800">{{ number_format($stats['total_born'] ?? 0) }}</p>
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Naissances cumulées") }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-teal-600 rounded-xl flex items-center justify-center text-white text-lg">🌿</div>
                    <div>
                        <p class="text-2xl font-black text-teal-800">{{ number_format($stats['total_weaned'] ?? 0) }}</p>
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Sevrages cumulés") }}</p>
                    </div>
                </div>
                @if($stats['avg_litter_size'] ?? null)
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white text-lg">📦</div>
                    <div>
                        <p class="text-2xl font-black text-indigo-800">{{ number_format($stats['avg_litter_size'], 1) }}</p>
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Taille de portée moy.") }} ({{ $stats['birth_events'] }} {{ __("mise") }}{{ $stats['birth_events'] > 1 ? 's' : '' }} {{ __("bas") }})</p>
                    </div>
                </div>
                @endif
                @if($stats['weaning_rate'] !== null)
                <div class="flex items-center gap-3">
                    <div @class([
                        'w-10 h-10 rounded-xl flex items-center justify-center text-white text-lg',
                        'bg-emerald-700' => $stats['weaning_rate'] >= 90,
                        'bg-amber-600'   => $stats['weaning_rate'] < 90 && $stats['weaning_rate'] >= 75,
                        'bg-rose-600'    => $stats['weaning_rate'] < 75,
                    ])>📊</div>
                    <div>
                        <p @class([
                            'text-2xl font-black',
                            'text-emerald-800' => $stats['weaning_rate'] >= 90,
                            'text-amber-700'   => $stats['weaning_rate'] < 90 && $stats['weaning_rate'] >= 75,
                            'text-rose-700'    => $stats['weaning_rate'] < 75,
                        ])>{{ number_format($stats['weaning_rate'], 1) }}%</p>
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("Taux de sevrage") }}</p>
                    </div>
                </div>
                @endif
            </div>
            @endif

            {{-- GRAPHIQUES --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest italic mb-6 leading-none"><i class="fas fa-skull-crossbones text-red-500 mr-2"></i> {{ __("Courbe de Mortalité (%)") }}</h3>
                    <div class="h-[300px]"><canvas id="mortalityChart"></canvas></div>
                </div>
                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest italic mb-6 leading-none"><i class="fas fa-tint text-blue-500 mr-2"></i> {{ __("Ration Aliment (kg) vs Eau (L)") }}</h3>
                    <div class="h-[300px]"><canvas id="hydrationChart"></canvas></div>
                </div>
            </div>

            {{-- CALENDRIER SANITAIRE --}}
            <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest italic flex items-center gap-2 leading-none"><i class="fas fa-calendar-check text-purple-500"></i> {{ __("Calendrier Sanitaire") }}</h3>
                </div>
                <div class="flex overflow-x-auto gap-4 pb-4 no-scrollbar">
                    @if($batch->protocol)
                        @foreach($batch->protocol->steps as $step)
                            @php 
                                $vaxDate = \Carbon\Carbon::parse($batch->transfer_date ?? $batch->start_date ?? $batch->arrival_date)->addDays($step->day_number)->startOfDay();
                                $isDone = $batch->healthChecks->filter(fn($i) => 
                                    str_contains(strtolower($i->product_name), strtolower($step->action_name))
                                )->isNotEmpty();
                                $isPast = $vaxDate->isPast() && !$isDone;
                                $isToday = $vaxDate->isToday() && !$isDone;
                            @endphp

                            <div @class([
                                'flex-none w-52 p-5 rounded-[2.5rem] border transition-all flex flex-col justify-between min-h-[160px]', 
                                'bg-emerald-50 border-emerald-100 font-black' => $isDone, 
                                'bg-red-50 border-red-200 shadow-lg animate-pulse' => $isPast, 
                                'bg-white border-blue-200 shadow-md' => $isToday,
                                'bg-white border-slate-100 opacity-60' => !$isDone && !$isPast && !$isToday
                            ])>
                                <div>
                                    <span @class(['text-[9px] font-black px-2 py-1 rounded-lg italic text-white', 'bg-emerald-600' => $isDone, 'bg-red-600 animate-pulse' => $isToday, 'bg-slate-900' => !$isDone && !$isToday])>{{ __("Jour") }} {{ $step->day_number }}</span>
                                    <p class="text-[11px] font-black text-slate-800 uppercase leading-tight mt-3">{{ $step->action_name }}</p>
                                    <p class="text-[9px] font-bold italic text-slate-400">{{ $vaxDate->translatedFormat('d F Y') }}</p>
                                </div>
                                @can('elevage.M')
                                    @if(!$isDone && $batch->status === 'Actif')
                                        <a href="{{ route('health.create', ['batch_id' => $batch->id, 'product_name' => $step->action_name]) }}" class="block w-full py-2 bg-slate-900 text-white text-center rounded-xl text-[8px] font-black uppercase">{{ __("Enregistrer") }}</a>
                                    @endif
                                @endcan
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- STOCKS DYNAMIQUES (phases d'aliment volaille uniquement) --}}
            @if($isVolaille)
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 text-left">
                @php
                    $batchType = ucfirst(strtolower($batch->type ?? 'Chair'));
                    if ($batchType === 'Ponte' or $batchType === 'Reproducteur') {
                        $feedPhases = [
                            'Ponte Démarrage (Poussin)', 
                            'Ponte Croissance (Poulette)', 
                            'Ponte 1 (Pic de ponte)', 
                            'Ponte 2 (Entretien)'
                        ];
                    } else {
                        $feedPhases = [
                            'Chair Démarrage', 
                            'Chair Croissance', 
                            'Chair Finition'
                        ];
                    }
                @endphp
                @foreach($feedPhases as $phaseName)
                    @php 
                        $stockItem = \App\Models\Stock::where('item_name', $phaseName)
                                        ->where('category', 'conso')
                                        ->first();
                        
                        if (!$stockItem) {
                            $stockItem = \App\Models\Stock::where('item_name', 'LIKE', "%$phaseName%")
                                            ->where('category', 'conso')
                                            ->first();
                        }
                        $qty = $stockItem ? (float)$stockItem->current_quantity : 0;
                        $unit = $stockItem ? $stockItem->unit : 'KG';
                        $isSac = ($unit === 'Sac');
                        $availableKg = $isSac ? ($qty * 50) : $qty;
                    @endphp

                    <div @class([
                        'bg-white p-6 rounded-[2.5rem] border shadow-sm relative overflow-hidden group transition-all',
                        'border-emerald-200 bg-emerald-50/20' => $stockItem && $stockItem->current_quantity > 0,
                        'border-slate-100 opacity-60' => !$stockItem || $stockItem->current_quantity <= 0
                    ])>
                        <p class="text-[8px] uppercase text-slate-400 mb-2 tracking-widest italic font-black">
                            {{ str_replace(['Chair ', 'Ponte '], '', $phaseName) }}
                        </p>
                        @if($stockItem)
                            <h4 class="text-xl font-black text-slate-800 leading-none tracking-tighter">
                                {{ number_format($availableKg, 1) }} <small class="text-[10px] text-slate-400">kg</small>
                            </h4>
                            <p class="text-[7px] {{ $isSac ? 'text-emerald-600' : 'text-blue-500' }} mt-2 font-black uppercase italic">
                                {{ $isSac ? __('Soit') . ' ' . number_format($qty, 1) . ' ' . __('Sacs') : __('Stock en Vrac') }}
                            </p>
                        @else
                            <h4 class="text-sm font-black text-slate-300 italic leading-none">{{ __("Non créé") }}</h4>
                            <p class="text-[7px] text-slate-300 mt-2 font-black uppercase italic">{{ __("Vérifier Inventaire") }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            @endif

            {{-- HISTORIQUE FLUX & APPROVISIONNEMENTS --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden mb-8 text-left italic font-bold">
                <div class="px-8 py-6 border-b border-slate-50 flex justify-between items-center bg-slate-50/30">
                    <h3 class="text-[10px] font-black text-slate-800 uppercase italic tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-conveyor-belt-arm text-orange-500"></i> {{ __("Journal des Flux & Approvisionnements") }}
                    </h3>
                    <span class="px-3 py-1 bg-slate-100 text-slate-500 rounded-full text-[7px] font-black uppercase">
                        {{ __("Secteur") }} {{ $batch->productionType?->name_fr ?? $batch->type }}
                    </span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-[8px] font-black uppercase text-slate-400 border-b border-slate-50 italic">
                                <th class="px-8 py-4">{{ __("Date") }}</th>
                                <th class="px-8 py-4">{{ __("Article") }}</th>
                                <th class="px-8 py-4 text-center">{{ __("Origine") }}</th>
                                <th class="px-8 py-4 text-center">{{ __("Quantité") }}</th>
                                <th class="px-8 py-4 text-right">{{ __("Montant") }}</th>
                                <th class="px-8 py-4 text-right">{{ __("Actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 text-[10px]">
                            @php
                                // Mouvements de stock "aliment volaille" (phases Chair/Ponte) :
                                // ne concerne que les lots de volaille.
                                if ($isVolaille) {
                                    $targetSectors = in_array($batch->type, ['Ponte', 'Reproducteur']) ? ['Ponte'] : ['Chair'];
                                    $feedStockIds = \App\Models\Stock::where('category', 'conso')
                                        ->where(function($q) use ($targetSectors) {
                                            $q->whereIn('metadata->poultry_type', $targetSectors)
                                            ->orWhere('item_name', 'LIKE', $targetSectors[0] . '%');
                                        })
                                        ->pluck('id');

                                    $movements = \App\Models\StockMovement::whereIn('stock_id', $feedStockIds)
                                                    ->where('notes', 'LIKE', "%{$batch->code}%")
                                                    ->latest()
                                                    ->get();
                                } else {
                                    $movements = collect();
                                }

                                $purchases = $batch->feedPurchases;
                            @endphp

                            @foreach($purchases->sortByDesc('purchase_date') as $purchase)
                                <tr class="hover:bg-blue-50/30 transition-colors border-l-4 border-l-emerald-500">
                                    <td class="px-8 py-4 text-slate-500">{{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d/m/Y') }}</td>
                                    <td class="px-8 py-4">
                                        <span class="text-slate-800 uppercase font-black">{{ $purchase->feed_type }}</span>
                                        <br><span class="text-[8px] text-slate-400 italic">{{ __("Fournisseur") }} : {{ $purchase->supplier ?? '--' }}</span>
                                    </td>
                                    <td class="px-8 py-4 text-center">
                                        <span class="bg-emerald-100 text-emerald-700 text-[7px] px-2 py-1 rounded-md uppercase font-black italic">{{ __("Achat") }}</span>
                                    </td>
                                    <td class="px-8 py-4 text-center text-emerald-600 font-black">+ {{ number_format($purchase->quantity, 1) }} kg</td>
                                    <td class="px-8 py-4 text-right"><span class="text-slate-900 font-black">{{ number_format($purchase->unit_price, 0) }} GNF</span></td>
                                    <td class="px-8 py-4 text-right">
                                        <div class="flex justify-end gap-3">
                                            @if($batch->status === 'Actif')
                                                @can('elevage.M')
                                                <a href="{{ route('feed-purchases.edit', $purchase->id) }}" class="text-slate-300 hover:text-blue-600 transition"><i class="fa-solid fa-pen-to-square"></i></a>
                                                @endcan
                                                @can('elevage.S')
                                                <form action="{{ route('feed-purchases.destroy', $purchase->id) }}" method="POST" onsubmit="return confirm(@json(__('Annuler cet achat ?')))">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="text-slate-300 hover:text-red-500 transition"><i class="fa-solid fa-trash-can"></i></button>
                                                </form>
                                                @endcan
                                            @else
                                                <i class="fa-solid fa-lock text-slate-200" title="{{ __("Lot clôturé") }}"></i>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach

                            @foreach($movements->where('type', 'out')->sortByDesc('created_at') as $m)
                                <tr class="hover:bg-rose-50/30 transition-colors border-l-4 border-l-rose-500">
                                    <td class="px-8 py-4 text-slate-400 font-medium">{{ $m->created_at->format('d/m H:i') }}</td>
                                    <td class="px-8 py-4 text-slate-800 uppercase font-black">{{ $m->stock->item_name ?? 'N/A' }}</td>
                                    <td class="px-8 py-4 text-center">
                                        <span class="bg-rose-100 text-rose-700 text-[7px] px-2 py-1 rounded-md uppercase font-black italic">{{ __("Consommation") }}</span>
                                    </td>
                                    <td class="px-8 py-4 text-center text-rose-600 font-black">- {{ number_format($m->quantity, 2) }} {{ $m->stock->unit ?? '' }}</td>
                                    <td class="px-8 py-4 text-right text-slate-400 italic font-medium leading-tight">{{ $m->notes }}</td>
                                    <td class="px-8 py-4 text-right">
                                        <i class="fa-solid fa-circle-check text-emerald-500/30"></i>
                                    </td>
                                </tr>
                            @endforeach

                            @if($purchases->isEmpty() && $movements->isEmpty())
                                <tr>
                                    <td colspan="6" class="px-8 py-10 text-center text-slate-300 uppercase text-[9px] tracking-widest">
                                        {{ __("Aucun mouvement enregistré") }}
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- HISTORIQUE DAILY --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden mb-8 text-left italic font-bold">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-[8px] font-black text-slate-400 uppercase bg-slate-50/50 border-b border-slate-50 italic leading-none">
                            <th class="px-6 py-5">{{ __("Date / Jour") }}</th>
                            <th class="px-4 py-5 text-red-500 text-center">{{ __("Morts") }}</th>
                            <th class="px-4 py-5 text-blue-600 text-center">{{ __("Conso. (L/kg)") }}</th>
                            <th class="px-4 py-5 text-orange-500 text-center">{{ __("T° Moyenne") }}</th>
                            <th class="px-4 py-5 text-emerald-600 text-center">{{ __("Poids (g)") }}</th>
                            <th class="px-4 py-5 text-purple-600 text-center">{{ __("Mvts (Inf.)") }}</th>
                            <th class="px-6 py-5 text-right">{{ __("Actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 text-[10px]">
                        @foreach($batch->dailyChecks->sortByDesc('check_date') as $check)
                            @php $checkAge = \Carbon\Carbon::parse($batch->arrival_date)->diffInDays($check->check_date) + 1; @endphp
                            <tr class="hover:bg-slate-50/80 transition-all">
                                <td class="px-6 py-5">
                                    <p class="font-black text-slate-800 text-sm leading-none">{{ $check->check_date->format('d/m/y') }}</p>
                                    <p class="text-[8px] font-bold text-blue-400 mt-1 uppercase tracking-widest leading-none">{{ __("Jour") }} {{ $checkAge }}</p>
                                </td>
                                <td class="px-4 py-5 text-center font-black {{ $check->mortality > 0 ? 'text-red-600 text-sm' : 'text-slate-200' }}">{{ $check->mortality }}</td>
                                <td class="px-4 py-5 text-center leading-tight">
                                    <p class="font-black text-slate-700">{{ number_format($check->water_consumed, 1) }}L</p>
                                    <p class="text-blue-500 font-black">{{ number_format($check->feed_consumed, 1) }}kg</p>
                                </td>
                                <td class="px-4 py-5 text-center font-black text-slate-700">{{ $check->avg_temperature ? number_format($check->avg_temperature, 1).'°C' : '--' }}</td>
                                <td class="px-4 py-5 text-center font-black text-emerald-600">{{ $check->avg_weight ? number_format($check->avg_weight * 1000, 0) : '--' }} g</td>
                                <td class="px-4 py-5 text-center leading-none">
                                    @if($check->qty_quarantine_in > 0) <span class="text-orange-500 font-black text-[8px] block">+{{ $check->qty_quarantine_in }}</span> @endif
                                    @if($check->qty_quarantine_out > 0) <span class="text-emerald-500 font-black text-[8px] block">-{{ $check->qty_quarantine_out }}</span> @endif
                                    @if(!$check->qty_quarantine_in && !$check->qty_quarantine_out) <span class="text-slate-200">-</span> @endif
                                </td>
                                <td class="px-8 py-4 text-right flex justify-end items-center gap-3">
                                    @if($check->extension && ($check->extension->qty_born > 0 || $check->extension->water_ph !== null))
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-emerald-100 text-emerald-700 text-[7px] font-black uppercase ml-2">
                                        @if($check->extension->water_ph !== null)🐟@else🐑@endif
                                        {{ __("Ext.") }}
                                    </span>
                                    @endif
                                    @if($batch->status === 'Actif')
                                        @can('elevage.M')
                                        <a href="{{ route('daily-checks.edit', $check->id) }}" class="text-slate-300 hover:text-blue-500 transition"><i class="fa-solid fa-pen-to-square text-[10px]"></i></a>
                                        @endcan
                                        @can('elevage.S')
                                        <form action="{{ route('daily-checks.destroy', $check->id) }}" method="POST" onsubmit="return confirm(@json(__('Supprimer ?')))">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-slate-300 hover:text-red-500 transition"><i class="fa-solid fa-trash-can text-[10px]"></i></button>
                                        </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- JOURNAL DES MUTATIONS --}}
            <div class="mt-12 bg-white rounded-[3rem] p-10 border border-slate-100 shadow-sm text-left font-bold italic">
                <div class="flex items-center gap-4 mb-10">
                    <div class="w-12 h-12 bg-rose-50 rounded-2xl flex items-center justify-center text-rose-500 shadow-sm"><i class="fa-solid fa-timeline text-xl"></i></div>
                    <div>
                        <h3 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Journal des Mutations") }}</h3>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-2 italic">{{ __("Traçabilité inter-bâtiments") }}</p>
                    </div>
                </div>

                <div class="relative space-y-8 before:absolute before:inset-0 before:ml-5 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-slate-200 before:to-transparent">
                    @php $history = is_array($batch->transfer_history) ? array_reverse($batch->transfer_history) : []; @endphp
                    @forelse($history as $log)
                        <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group">
                            <div class="flex items-center justify-center w-10 h-10 rounded-full border border-white bg-slate-900 text-white shadow shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 transition-transform duration-300 group-hover:scale-125 z-10">
                                <i class="fa-solid fa-check text-[10px]"></i>
                            </div>
                            <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-slate-50 p-6 rounded-[2rem] border border-slate-100 shadow-sm hover:border-rose-200 transition-colors">
                                <div class="flex items-center justify-between mb-4">
                                    <time class="text-[10px] font-black text-rose-500 uppercase italic leading-none">{{ \Carbon\Carbon::parse($log['date'])->format('d M Y') }}</time>
                                    <p class="text-[10px] font-black uppercase text-slate-400">
                                        {{ __("Opérateur") }} : {{ $log['performed_by'] ?? __('Système') }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-3 mb-4">
                                    <span class="text-xs font-black text-slate-400 uppercase italic">{{ $log['from_building'] }}</span>
                                    <i class="fa-solid fa-arrow-right-long text-slate-300"></i>
                                    <span class="text-xs font-black text-slate-800 uppercase italic">{{ $log['to_building'] }}</span>
                                </div>
                                @if(isset($log['quantity_at_transfer']))
                                    <p class="text-[8px] font-black text-blue-500 uppercase mb-2">{{ __("Effectif au transfert") }} : {{ $log['quantity_at_transfer'] }} {{ __("sujets") }}</p>
                                @endif
                                <p class="text-[9px] font-bold text-slate-500 leading-relaxed italic border-t border-slate-200 pt-3 uppercase">{{ $log['notes'] ?? '' }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-10"><p class="text-[10px] font-black text-slate-300 uppercase italic tracking-widest">{{ __("Aucun mouvement.") }}</p></div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL DE MUTATION --}}
    @can('elevage.M')
    <div id="modal-transfer" class="hidden fixed inset-0 bg-slate-900/95 backdrop-blur-xl z-[100] flex items-center justify-center p-4 italic font-bold">
        <div class="bg-white w-full max-w-2xl rounded-[4rem] shadow-2xl overflow-hidden border border-white/20 relative">
            <form action="{{ route('batches.transfer', $batch->id) }}" method="POST" class="p-12 relative z-10 text-left">
                @csrf
                <div class="mb-10">
                    <h3 class="text-3xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Mutation de Lot") }}</h3>
                    <p class="text-[10px] font-black text-rose-500 uppercase tracking-[0.2em] mt-3 italic">{{ __("Effectif actuel") }} : <span class="text-slate-900">{{ $currentEffectif }} {{ __("sujets") }}</span></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-6">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic">{{ __("Bâtiment de Destination") }}</label>
                            <select name="target_building_id" required class="w-full p-5 bg-slate-50 rounded-[2rem] border-none shadow-inner font-black text-slate-700 italic uppercase text-xs appearance-none">
                                <option value="">{{ __("-- Sélectionner --") }}</option>
                                @foreach($buildings as $building)
                                    @if($building->type === $batch->type || $building->type === 'mixte')
                                        <option value="{{ $building->id }}" {{ $building->id == $batch->building_id ? 'disabled' : '' }}>
                                            {{ $building->name }} ({{ __("Cap") }}: {{ $building->capacity }} | {{ __("Type") }}: {{ $building->type }})
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic">{{ __("Date du Mouvement") }}</label>
                            <input type="date" name="transfer_date" value="{{ date('Y-m-d') }}" required class="w-full p-5 bg-slate-50 rounded-[2rem] border-none shadow-inner font-black text-blue-600 italic text-xs">
                        </div>
                    </div>
                    <div class="space-y-6">
                        <div>
                            <label class="block text-[10px] font-black text-blue-500 uppercase mb-3 ml-2 italic">{{ __("Nouveau Programme") }}</label>
                            <select name="new_protocol_id" id="protocol-select" required class="w-full p-5 bg-slate-100 rounded-[2rem] border-none shadow-inner font-black text-blue-600 italic uppercase text-xs appearance-none">
                                <option value="" data-type="all">{{ __("-- Appliquer Protocole --") }}</option>
                                @foreach($protocols as $protocol)
                                    <option value="{{ $protocol->id }}" data-type="{{ $protocol->type }}">{{ $protocol->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2 italic">{{ __("Nouvelle Phase") }}</label>
                            <select name="new_phase" required class="w-full p-5 bg-slate-50 rounded-[2rem] border-none shadow-inner font-black text-slate-700 italic uppercase text-xs appearance-none">
                                @if($isVolaille)
                                    @if(in_array($batch->type, ['ponte', 'reproducteur', 'poussiniere']))
                                        <option value="poussiniere" {{ $batch->production_phase == 'poussiniere' ? 'selected' : '' }}>{{ __("Poussinière") }}</option>
                                        <option value="ponte" {{ $batch->production_phase == 'ponte' ? 'selected' : '' }}>{{ __("Ponte Active") }}</option>
                                        <option value="reproducteur" {{ $batch->production_phase == 'reproducteur' ? 'selected' : '' }}>{{ __("Reproducteurs") }}</option>
                                    @endif
                                    @if($batch->type == 'chair')
                                        <option value="chair" selected>{{ __("Poulet de Chair") }}</option>
                                        <option value="poussiniere">{{ __("Poussinière") }}</option>
                                    @endif
                                @else
                                    {{-- Espèces non-volailles : la mutation ne change pas la phase
                                         de production, on conserve la phase/le type courant. --}}
                                    <option value="{{ $batch->production_phase ?? $batch->type }}" selected>
                                        {{ $batch->productionType?->name_fr ?? ucfirst($batch->type) }}
                                    </option>
                                @endif
                            </select>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <textarea name="notes" placeholder="{{ __("Notes de traçabilité...") }}" class="w-full p-6 bg-slate-50 rounded-[2.5rem] border-none shadow-inner font-bold text-slate-500 italic text-[10px] uppercase"></textarea>
                    </div>
                </div>

                <div class="flex gap-4 mt-10">
                    <button type="button" onclick="document.getElementById('modal-transfer').classList.add('hidden')" class="flex-1 py-6 text-[10px] font-black uppercase text-slate-400">{{ __("Annuler") }}</button>
                    <button type="submit" class="flex-[2] py-6 bg-rose-600 text-white rounded-[2.5rem] text-[10px] font-black uppercase italic shadow-2xl hover:bg-slate-900 transition-all">{{ __("Exécuter la mutation") }}</button>
                </div>
            </form>
        </div>
    </div>
    @endcan

    {{-- MODALE RAVITAILLEMENT (AFFECTATION DIRECTE AU LOT) --}}
    @can('elevage.C')
    @include('batches.partials.feed-modal')
    @endcan

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // GESTION DE LA MODALE STOCK/ALIMENT
        function openFeedModal() { 
            document.getElementById('feedModal').classList.remove('hidden'); 
        }
        function closeFeedModal() { 
            document.getElementById('feedModal').classList.add('hidden'); 
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            
            // 1. GESTION DES ERREURS DE TRANSFERT
            @if($errors->has('error'))
                const modalTransfer = document.getElementById('modal-transfer');
                if (modalTransfer) modalTransfer.classList.remove('hidden');
            @endif

            // 2. GESTION DES ERREURS D'ACHAT DIRECT (Rouvre la modale si échec de validation)
            @if($errors->has('unit_price') || $errors->has('quantity'))
                openFeedModal();
            @endif

            // 3. FILTRAGE DU PROTOCOLE
            const batchType = "{{ $batch->type }}";
            const protocolSelect = document.getElementById('protocol-select');
            if (protocolSelect) {
                const options = protocolSelect.options;
                let matchCount = 0;
                for (let i = 0; i < options.length; i++) {
                    const optType = options[i].getAttribute('data-type');
                    if (optType !== 'all' && optType === batchType) matchCount++;
                }

                // Si aucun protocole n'est défini pour ce type (ex: espèces
                // non-volailles sans protocole dédié), on affiche tous les
                // protocoles plutôt que de bloquer la mutation.
                for (let i = 0; i < options.length; i++) {
                    const optType = options[i].getAttribute('data-type');
                    options[i].style.display = (matchCount === 0 || optType === batchType || optType === 'all') ? 'block' : 'none';
                }
            }

            // 4. INITIALISATION DES GRAPHIQUES (Correction syntaxe Chart.js)
            const raw = @json($batch->dailyChecks->sortBy('check_date')->values());
            
            if (raw.length > 0) {
                const labels = raw.map((_, i) => 'J' + (i + 1));
                const commonOptions = { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { legend: { display: false } } 
                };

                // GRAPHIQUE MORTALITÉ
                const ctxMortality = document.getElementById('mortalityChart');
                if (ctxMortality) {
                    new Chart(ctxMortality, {
                        type: 'line', // <-- OBLIGATOIRE À LA RACINE
                        data: { 
                            labels: labels, 
                            datasets: [{ 
                                data: raw.map((c, i, a) => (a.slice(0, i + 1).reduce((s, x) => s + x.mortality, 0) / {{ $batch->initial_quantity }}) * 100), 
                                borderColor: '#ef4444', 
                                borderWidth: 3, 
                                tension: 0.4 
                            }] 
                        },
                        options: commonOptions
                    });
                }

                // GRAPHIQUE HYDRATATION / ALIMENTATION (Graphique Mixte)
                const ctxHydration = document.getElementById('hydrationChart');
                if (ctxHydration) {
                    new Chart(ctxHydration, {
                        type: 'bar', // <-- OBLIGATOIRE (Définit le type de base du graphique mixte)
                        data: { 
                            labels: labels, 
                            datasets: [
                                { type: 'line', data: raw.map(c => c.feed_consumed), borderColor: '#1e293b', borderWidth: 2 },
                                { type: 'bar', data: raw.map(c => c.water_consumed), backgroundColor: 'rgba(59, 130, 246, 0.2)' }
                            ]
                        },
                        options: commonOptions
                    });
                }
            }
        });
    </script>
</x-app-layout>
