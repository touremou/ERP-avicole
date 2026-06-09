<x-app-layout>
    @php
        $currency       = setting('general.currency', 'GNF');
        $df             = setting('general.date_format', 'd/m/Y');
        $batchTypes     = ['chair' => '🍗 Chair', 'ponte' => '🥚 Ponte', 'poussiniere' => '🐥 Poussinière', 'reproducteur' => '🐓 Reproducteur'];
    @endphp

    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div>
                <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    📊 Analyse Financière — Coûts de Production
                </h2>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-3 italic leading-none">
                    Alimentation · Santé · Acquisition · Coût par tête
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('reports.index') }}" class="flex items-center justify-center w-11 h-11 bg-white border border-slate-200 text-slate-400 hover:text-rose-600 rounded-xl transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold text-slate-700">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">

            {{-- ══════════════════════════════════════════════════
                 PANNEAU DE FILTRES
            ══════════════════════════════════════════════════ --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-8 text-left">
                <form method="GET" action="{{ route('reports.monthly') }}" id="filter-form" class="space-y-6">

                    {{-- Ligne 1 : Statut · Type · Année · Mois --}}
                    <div class="flex flex-wrap gap-4 items-end">

                        {{-- STATUT --}}
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 leading-none">Statut</label>
                            <div class="bg-slate-100 p-1.5 rounded-xl flex gap-1">
                                @foreach(['all' => 'Tous', 'actif' => '🟢 Actifs', 'termine' => '🔵 Terminés'] as $key => $label)
                                    <button type="button" onclick="setFilter('status','{{ $key }}')"
                                        @class(['px-4 py-2 rounded-lg text-[9px] font-black uppercase italic transition-all',
                                            'bg-white shadow text-slate-900' => $statusFilter == $key,
                                            'text-slate-400 hover:text-slate-600' => $statusFilter != $key])>
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                            <input type="hidden" name="status" id="f_status" value="{{ $statusFilter }}">
                        </div>

                        {{-- TYPE --}}
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 leading-none">Type de Lot</label>
                            <div class="bg-slate-100 p-1.5 rounded-xl flex flex-wrap gap-1">
                                <button type="button" onclick="setFilter('type','all')"
                                    @class(['px-4 py-2 rounded-lg text-[9px] font-black uppercase italic transition-all',
                                        'bg-white shadow text-slate-900' => $typeFilter == 'all',
                                        'text-slate-400 hover:text-slate-600' => $typeFilter != 'all'])>
                                    Tous
                                </button>
                                @foreach($batchTypes as $key => $label)
                                    <button type="button" onclick="setFilter('type','{{ $key }}')"
                                        @class(['px-4 py-2 rounded-lg text-[9px] font-black uppercase italic transition-all',
                                            'bg-white shadow text-slate-900' => $typeFilter == $key,
                                            'text-slate-400 hover:text-slate-600' => $typeFilter != $key])>
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                            <input type="hidden" name="type" id="f_type" value="{{ $typeFilter }}">
                        </div>

                        {{-- ANNÉE --}}
                        @if(! $useDateRange)
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 leading-none">Année</label>
                            <select name="year" onchange="this.form.submit()" class="appearance-none pl-4 pr-10 py-3 bg-slate-900 text-white border-none rounded-xl text-[10px] font-black uppercase italic shadow-lg cursor-pointer">
                                @foreach($availableYears as $yr)
                                    <option value="{{ $yr }}" {{ $currentYear == $yr ? 'selected' : '' }}>{{ $yr }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- MOIS --}}
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 leading-none">Mois</label>
                            <select name="month" onchange="this.form.submit()" class="appearance-none pl-4 pr-10 py-3 bg-slate-900 text-white border-none rounded-xl text-[10px] font-black uppercase italic shadow-lg cursor-pointer">
                                <option value="all" {{ $monthFilter == 'all' ? 'selected' : '' }}>Toute l'année</option>
                                @foreach([1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'] as $num => $name)
                                    <option value="{{ $num }}" {{ $monthFilter == $num ? 'selected' : '' }}>{{ strtoupper($name) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                    </div>

                    {{-- Ligne 2 : Plage personnalisée --}}
                    <div class="flex flex-wrap gap-4 items-end pt-2 border-t border-slate-50">
                        <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest w-full leading-none">— ou plage de dates personnalisée (prioritaire sur année / mois) —</p>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 leading-none">Du</label>
                            <input type="date" name="date_from" value="{{ $dateFrom }}" class="px-4 py-3 bg-blue-50 border border-blue-100 rounded-xl text-[10px] font-black text-slate-700 focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 leading-none">Au</label>
                            <input type="date" name="date_to" value="{{ $dateTo }}" class="px-4 py-3 bg-blue-50 border border-blue-100 rounded-xl text-[10px] font-black text-slate-700 focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <button type="submit" class="px-8 py-3 bg-blue-600 text-white rounded-xl text-[10px] font-black uppercase italic hover:bg-blue-700 transition shadow-lg">
                            <i class="fas fa-filter mr-2"></i> Filtrer
                        </button>
                        @if($useDateRange || $typeFilter !== 'all' || $statusFilter !== 'all' || $monthFilter !== 'all')
                        <a href="{{ route('reports.monthly') }}" class="px-8 py-3 bg-slate-100 text-slate-500 rounded-xl text-[10px] font-black uppercase italic hover:bg-red-50 hover:text-red-500 transition no-underline">
                            <i class="fas fa-times mr-2"></i> Réinitialiser
                        </a>
                        @endif
                    </div>
                </form>
            </div>

            {{-- ══════════════════════════════════════════════════
                 RÉCAPITULATIF GLOBAL (KPIs)
            ══════════════════════════════════════════════════ --}}
            @if(! empty($monthlyData))
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                {{-- Coût Total --}}
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-xl text-left col-span-2 md:col-span-1">
                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-[0.2em] leading-none mb-3">Coût Total Consolidé</p>
                    <p class="text-2xl font-black italic tracking-tighter leading-none">
                        {{ number_format($globalStats['total_cost'], 0, ',', ' ') }}
                        <small class="text-[10px] opacity-40 font-black">{{ $currency }}</small>
                    </p>
                    <p class="text-[8px] text-slate-400 font-black uppercase italic mt-2">{{ number_format($globalStats['heads']) }} têtes suivies</p>
                </div>

                {{-- Coût / Tête --}}
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-left">
                    <p class="text-[8px] font-black uppercase text-slate-400 tracking-[0.2em] leading-none mb-3">Coût / Tête</p>
                    <p class="text-2xl font-black text-slate-800 italic tracking-tighter leading-none">
                        {{ number_format($globalStats['cost_per_head'], 0, ',', ' ') }}
                        <small class="text-[10px] opacity-40">{{ $currency }}</small>
                    </p>
                    <p class="text-[8px] text-slate-300 font-black uppercase italic mt-2">Coût moyen production</p>
                </div>

                {{-- Consommation Aliment --}}
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-left">
                    <p class="text-[8px] font-black uppercase text-orange-400 tracking-[0.2em] leading-none mb-3">Charge Aliment</p>
                    <p class="text-2xl font-black text-orange-600 italic tracking-tighter leading-none">
                        {{ number_format($globalStats['feed_cost'], 0, ',', ' ') }}
                        <small class="text-[10px] opacity-40">{{ $currency }}</small>
                    </p>
                    <p class="text-[8px] text-slate-300 font-black uppercase italic mt-2">
                        {{ number_format($globalStats['feed_qty'], 0) }} kg · {{ $globalStats['feed_pct'] }}% du total
                    </p>
                </div>

                {{-- Santé --}}
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-left">
                    <p class="text-[8px] font-black uppercase text-rose-400 tracking-[0.2em] leading-none mb-3">Invest. Santé</p>
                    <p class="text-2xl font-black text-rose-600 italic tracking-tighter leading-none">
                        {{ number_format($globalStats['health_cost'], 0, ',', ' ') }}
                        <small class="text-[10px] opacity-40">{{ $currency }}</small>
                    </p>
                    <p class="text-[8px] text-slate-300 font-black uppercase italic mt-2">
                        {{ $globalStats['health_pct'] }}% du coût total
                    </p>
                </div>
            </div>

            {{-- Barre de répartition --}}
            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-left">
                <p class="text-[9px] font-black uppercase text-slate-400 tracking-[0.2em] leading-none mb-4">Répartition des Charges</p>
                <div class="flex rounded-xl overflow-hidden h-5 w-full">
                    @if($globalStats['acq_pct'] > 0)
                    <div class="bg-blue-500 flex items-center justify-center text-[8px] text-white font-black transition-all" style="width: {{ $globalStats['acq_pct'] }}%">
                        @if($globalStats['acq_pct'] > 8) {{ $globalStats['acq_pct'] }}% @endif
                    </div>
                    @endif
                    @if($globalStats['feed_pct'] > 0)
                    <div class="bg-orange-400 flex items-center justify-center text-[8px] text-white font-black transition-all" style="width: {{ $globalStats['feed_pct'] }}%">
                        @if($globalStats['feed_pct'] > 8) {{ $globalStats['feed_pct'] }}% @endif
                    </div>
                    @endif
                    @if($globalStats['health_pct'] > 0)
                    <div class="bg-rose-400 flex items-center justify-center text-[8px] text-white font-black transition-all" style="width: {{ $globalStats['health_pct'] }}%">
                        @if($globalStats['health_pct'] > 8) {{ $globalStats['health_pct'] }}% @endif
                    </div>
                    @endif
                </div>
                <div class="flex gap-6 mt-3">
                    <span class="flex items-center gap-2 text-[8px] font-black uppercase text-slate-400"><span class="w-3 h-3 bg-blue-500 rounded-sm"></span>Acquisition {{ $globalStats['acq_pct'] }}%</span>
                    <span class="flex items-center gap-2 text-[8px] font-black uppercase text-slate-400"><span class="w-3 h-3 bg-orange-400 rounded-sm"></span>Aliment {{ $globalStats['feed_pct'] }}%</span>
                    <span class="flex items-center gap-2 text-[8px] font-black uppercase text-slate-400"><span class="w-3 h-3 bg-rose-400 rounded-sm"></span>Santé {{ $globalStats['health_pct'] }}%</span>
                </div>
            </div>
            @endif

            {{-- ══════════════════════════════════════════════════
                 CHRONOLOGIE PAR MOIS (ou plage unique)
            ══════════════════════════════════════════════════ --}}
            @if(empty($monthlyData))
                <div class="py-40 text-center flex flex-col items-center justify-center">
                    <div class="w-20 h-20 bg-slate-50 rounded-[2.5rem] flex items-center justify-center text-slate-200 mb-6">
                        <i class="fa-solid fa-chart-pie text-4xl"></i>
                    </div>
                    <p class="text-slate-400 uppercase text-xs font-black tracking-[0.3em] italic">Aucun flux financier pour cette période</p>
                    <a href="{{ route('reports.monthly') }}" class="mt-6 px-8 py-3 bg-slate-900 text-white rounded-xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-600 transition">
                        Réinitialiser les filtres
                    </a>
                </div>
            @else
                @php
                    $monthLabels = [0 => 'Plage personnalisée', 1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                                    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre',
                                    10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
                @endphp
                @foreach($monthLabels as $num => $name)
                    @if(isset($monthlyData[$num]))
                    @php
                        $mRows     = $monthlyData[$num];
                        $mTotal    = collect($mRows)->sum('health') + collect($mRows)->sum('feed_cost');
                        $mFeedQty  = collect($mRows)->sum('feed_qty');
                    @endphp
                    <div class="relative pl-12 border-l-4 border-slate-100 pb-20 last:pb-0 text-left">
                        <div class="absolute -left-[15px] top-0 w-7 h-7 bg-blue-600 rounded-full border-4 border-white shadow-xl shadow-blue-200 animate-pulse"></div>

                        <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-12 gap-4">
                            <div>
                                <h3 class="text-4xl font-black text-slate-900 uppercase tracking-tighter leading-none italic">
                                    {{ $name }}
                                    @if($useDateRange && $num === 0)
                                        <span class="text-base text-blue-500 ml-2">{{ \Carbon\Carbon::parse($dateFrom)->format($df) }} → {{ \Carbon\Carbon::parse($dateTo)->format($df) }}</span>
                                    @elseif(! $useDateRange)
                                        <span class="text-base text-slate-300 ml-2">{{ $currentYear }}</span>
                                    @endif
                                </h3>
                                <p class="text-[10px] text-blue-500 uppercase mt-2 font-black tracking-widest italic leading-none">
                                    {{ count($mRows) }} lot(s) · {{ number_format($mFeedQty, 0) }} kg aliment consommés
                                </p>
                            </div>
                            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-xl text-right">
                                <p class="text-[9px] text-slate-400 uppercase mb-2 tracking-[0.2em] font-black italic">Charges Consolidées</p>
                                <p class="text-3xl font-black text-slate-900 tracking-tighter italic">
                                    {{ number_format($mTotal, 0, ',', ' ') }} <small class="text-xs uppercase opacity-40">{{ $currency }}</small>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                            @foreach($mRows as $batchId => $data)
                            @php
                                $totalCard    = ($data['health'] ?? 0) + ($data['feed_cost'] ?? 0);
                                $totalWithAcq = $totalCard + ($data['acquisition_cost'] ?? 0);
                                $costPerHead  = $data['batch']->initial_quantity > 0
                                    ? $totalWithAcq / $data['batch']->initial_quantity : 0;
                                $feedPct      = $totalCard > 0 ? round($data['feed_cost'] / $totalCard * 100) : 0;
                                $healthPct    = 100 - $feedPct;
                            @endphp
                            <div class="bg-white rounded-[3.5rem] border border-slate-50 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 group overflow-hidden">
                                <div class="p-8">
                                    {{-- En-tête --}}
                                    <div class="flex justify-between items-start mb-8">
                                        <div>
                                            <p class="text-xl font-black text-slate-800 leading-none uppercase tracking-tighter italic">{{ $data['batch']->code }}</p>
                                            <p class="text-[9px] text-slate-400 mt-1.5 uppercase font-black tracking-widest italic">
                                                {{ $data['batch']->building->name ?? 'ZONE LIBRE' }}
                                                · {{ strtoupper($data['batch']->type) }}
                                            </p>
                                        </div>
                                        <span @class([
                                            'px-3 py-1.5 rounded-xl text-[8px] font-black uppercase italic shadow-sm',
                                            'bg-emerald-50 text-emerald-600 border border-emerald-100' => $data['batch']->status === 'Actif',
                                            'bg-blue-50 text-blue-600 border border-blue-100' => $data['batch']->status !== 'Actif',
                                        ])>{{ $data['batch']->status }}</span>
                                    </div>

                                    <div class="space-y-3">
                                        {{-- SANTÉ --}}
                                        <div class="flex justify-between items-center bg-slate-50 p-4 rounded-[1.5rem] border border-slate-100 group-hover:bg-white transition-colors">
                                            <div class="flex items-center gap-3">
                                                <i class="fa-solid fa-pills text-rose-400 text-sm"></i>
                                                <span class="text-[9px] font-black uppercase text-slate-400 italic">Santé</span>
                                            </div>
                                            <span class="text-sm font-black text-rose-600 italic">
                                                {{ number_format($data['health'] ?? 0, 0, ',', ' ') }}
                                                <small class="text-[8px] opacity-50">{{ $currency }}</small>
                                            </span>
                                        </div>

                                        {{-- ALIMENT --}}
                                        <div class="bg-slate-50 p-4 rounded-[1.5rem] border border-slate-100 group-hover:bg-white transition-colors space-y-2">
                                            <div class="flex justify-between items-center">
                                                <div class="flex items-center gap-3">
                                                    <i class="fa-solid fa-wheat-awn text-orange-400 text-sm"></i>
                                                    <span class="text-[9px] font-black uppercase text-slate-400 italic">Aliment</span>
                                                </div>
                                                <span class="text-sm font-black text-orange-600 italic">
                                                    {{ number_format($data['feed_cost'] ?? 0, 0, ',', ' ') }}
                                                    <small class="text-[8px] opacity-50">{{ $currency }}</small>
                                                </span>
                                            </div>
                                            <div class="flex justify-between items-center px-2 py-1.5 bg-white/60 rounded-lg">
                                                <span class="text-[8px] text-slate-400 italic uppercase font-black">Conso brute</span>
                                                <span class="text-[8px] font-black text-slate-600 uppercase">{{ number_format($data['feed_qty'] ?? 0, 1) }} kg</span>
                                            </div>
                                            @if(($data['avg_price_per_kg'] ?? 0) > 0)
                                            <div class="flex justify-between items-center px-2 py-1.5 bg-white/60 rounded-lg">
                                                <span class="text-[8px] text-slate-400 italic uppercase font-black">Prix moyen/kg</span>
                                                <span class="text-[8px] font-black text-slate-600 uppercase">{{ number_format($data['avg_price_per_kg'], 0, ',', ' ') }} {{ $currency }}</span>
                                            </div>
                                            @endif
                                        </div>

                                        {{-- ACQUISITION --}}
                                        @if(($data['acquisition_cost'] ?? 0) > 0)
                                        <div class="flex justify-between items-center bg-slate-50 p-4 rounded-[1.5rem] border border-slate-100 group-hover:bg-white transition-colors">
                                            <div class="flex items-center gap-3">
                                                <i class="fa-solid fa-tag text-blue-400 text-sm"></i>
                                                <span class="text-[9px] font-black uppercase text-slate-400 italic">Acquisition</span>
                                            </div>
                                            <span class="text-sm font-black text-blue-600 italic">
                                                {{ number_format($data['acquisition_cost'], 0, ',', ' ') }}
                                                <small class="text-[8px] opacity-50">{{ $currency }}</small>
                                            </span>
                                        </div>
                                        @endif
                                    </div>

                                    {{-- Barre aliment/santé --}}
                                    @if($totalCard > 0)
                                    <div class="mt-5 flex rounded-lg overflow-hidden h-2.5">
                                        <div class="bg-orange-400 transition-all" style="width: {{ $feedPct }}%"></div>
                                        <div class="bg-rose-400 transition-all" style="width: {{ $healthPct }}%"></div>
                                    </div>
                                    @endif

                                    {{-- TOTAL + COÛT/TÊTE --}}
                                    <div class="mt-6 pt-6 border-t-2 border-dashed border-slate-100 flex justify-between items-end">
                                        <div>
                                            <p class="text-[9px] uppercase text-slate-500 font-black italic leading-none mb-1">Total Décaissement</p>
                                            <p class="text-[8px] text-slate-300 font-black uppercase italic">
                                                <i class="fas fa-user text-[7px] mr-1"></i> {{ number_format($costPerHead, 0, ',', ' ') }} {{ $currency }}/tête
                                                ({{ number_format($data['batch']->initial_quantity) }} sujets)
                                            </p>
                                        </div>
                                        <p class="text-2xl font-black text-slate-900 tracking-tighter italic">
                                            {{ number_format($totalWithAcq, 0, ',', ' ') }}
                                            <small class="text-[10px] opacity-40">{{ $currency }}</small>
                                        </p>
                                    </div>
                                </div>

                                <a href="{{ route('batches.show', $data['batch']->id) }}" class="block w-full py-4 bg-slate-900 text-white text-center text-[9px] font-black uppercase tracking-[0.3em] italic hover:bg-blue-600 transition-all no-underline">
                                    <i class="fas fa-arrow-right mr-2"></i> Analyser le lot
                                </a>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                @endforeach
            @endif

        </div>
    </div>

    <script>
        function setFilter(name, value) {
            document.getElementById('f_' + name).value = value;
            document.getElementById('filter-form').submit();
        }
    </script>
</x-app-layout>
