<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-indigo-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-calendar-days text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Planification des Bandes") }}</h2>
                    <p class="text-[10px] font-black text-indigo-600 uppercase tracking-[0.2em] mt-2 italic">
                        {{ $from->translatedFormat('M Y') }} → {{ $to->translatedFormat('M Y') }}
                    </p>
                </div>
            </div>
            @can('planning.C')
            <a href="{{ route('planning.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-indigo-600 transition-all shadow-2xl italic no-underline flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> {{ __("Planifier une Bande") }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- ═══ KPI CARDS ═══ --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-indigo-500 uppercase tracking-widest mb-1">{{ __("Bandes planifiées") }}</p>
                    <p class="text-3xl font-black text-slate-900">{{ $kpi['total_planned'] }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                    'bg-amber-50 border-amber-200' => $kpi['arriving_7days'] > 0,
                    'bg-white border-slate-100' => $kpi['arriving_7days'] === 0])>
                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1">{{ __("Arrivées 7j") }}</p>
                    <p class="text-3xl font-black {{ $kpi['arriving_7days'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $kpi['arriving_7days'] }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                    'bg-red-50 border-red-200 animate-pulse' => $kpi['overdue_orders'] > 0,
                    'bg-white border-slate-100' => $kpi['overdue_orders'] === 0])>
                    <p class="text-[8px] font-black text-red-500 uppercase tracking-widest mb-1">{{ __("Commandes retard") }}</p>
                    <p class="text-3xl font-black {{ $kpi['overdue_orders'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $kpi['overdue_orders'] }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Lots actifs") }}</p>
                    <p class="text-3xl font-black text-emerald-600">{{ $kpi['active_batches'] }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Effectif total") }}</p>
                    <p class="text-3xl font-black text-slate-900">{{ number_format($kpi['total_birds']) }}</p>
                </div>
            </div>

            {{-- ═══ ALERTES ═══ --}}
            @if(count($alerts) > 0)
            <div class="mb-8 space-y-3">
                @foreach($alerts as $alert)
                <div @class(['p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest flex items-center gap-3',
                    'bg-red-500 text-white' => ($alert['severity'] ?? '') === 'critique',
                    'bg-amber-500 text-white' => ($alert['severity'] ?? '') === 'attention',
                    'bg-blue-50 text-blue-700 border border-blue-200' => ($alert['severity'] ?? '') === 'info'])>
                    <i class="fa-solid {{ $alert['icon'] ?? 'fa-bell' }}"></i>
                    <span class="normal-case text-[9px]">{{ $alert['message'] }}</span>
                </div>
                @endforeach
            </div>
            @endif

            {{-- ═══ OCCUPATION BÂTIMENTS ═══ --}}
            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm mb-6">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-5 flex items-center gap-2">
                    <i class="fa-solid fa-chart-bar text-indigo-500"></i> {{ __("Occupation des bâtiments") }}
                </h3>
                <div class="space-y-3">
                    @foreach($occupancy as $occ)
                    @php $rate = $occ['occupancy_rate']; @endphp
                    <div class="flex items-center gap-3">
                        {{-- Nom du bâtiment --}}
                        <div class="w-32 shrink-0">
                            <span class="text-[10px] font-black text-slate-700 uppercase truncate block">{{ $occ['building']->name }}</span>
                            @if($occ['active_batches']->count() > 0)
                                <span class="text-[7px] text-blue-400 font-black">
                                    {{ $occ['active_batches']->pluck('type')->unique()->implode(', ') }}
                                </span>
                            @endif
                        </div>

                        {{-- Barre de progression --}}
                        <div class="flex-1 bg-slate-100 rounded-full h-6 overflow-hidden relative">
                            <div @class(['h-6 rounded-full transition-all duration-500',
                                'bg-red-500'     => $rate >= 95,
                                'bg-emerald-500' => $rate >= 50 && $rate < 95,
                                'bg-amber-400'   => $rate >= 20 && $rate < 50,
                                'bg-slate-200'   => $rate < 20]) style="width: {{ max(2, $rate) }}%"></div>
                            <span class="absolute inset-0 flex items-center justify-center text-[8px] font-black {{ $rate >= 40 ? 'text-white' : 'text-slate-500' }}">
                                {{ number_format($occ['current_birds']) }} / {{ number_format($occ['capacity']) }} — {{ $rate }}%
                            </span>
                        </div>

                        {{-- Statut --}}
                        <div class="w-28 shrink-0 text-right">
                            @if($occ['is_empty'])
                                <span @class(['text-[8px] font-black', 'text-red-500 animate-pulse' => $occ['idle_days'] > 30, 'text-slate-400' => $occ['idle_days'] <= 30])>
                                    {{ __("Vide :countj", ['count' => $occ['idle_days']]) }}
                                </span>
                            @elseif($occ['is_full'])
                                <span class="text-[8px] font-black text-red-500">{{ __("Plein") }}</span>
                            @else
                                <span class="text-[8px] font-black text-emerald-500">{{ __("Actif") }}</span>
                            @endif
                            @if($occ['planned_days'] > 0)
                                <span class="text-[7px] text-blue-400 block">{{ __(":countj planif.", ['count' => $occ['planned_days']]) }}</span>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- ═══ FILTRE DATES ═══ --}}
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                    <i class="fa-solid fa-list text-indigo-500"></i> {{ __("Planning des bandes") }} ({{ $plans->count() }})
                </h3>
                <form method="GET" class="flex gap-2 items-center">
                    <input type="date" name="from" value="{{ $from->toDateString() }}" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-[10px] font-black outline-none">
                    <span class="text-[8px] text-slate-300">→</span>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-[10px] font-black outline-none">
                    <button class="bg-slate-900 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase border-none cursor-pointer hover:bg-indigo-600 transition-all">{{ __("Filtrer") }}</button>
                </form>
            </div>

            {{-- ═══ TABLEAU DES BANDES ═══ --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 bg-slate-50">
                                <th class="px-5 py-4 text-left">{{ __("Bâtiment") }}</th>
                                <th class="px-3 py-4 text-left">{{ __("Type / Souche") }}</th>
                                <th class="px-3 py-4 text-center">{{ __("Qté") }}</th>
                                <th class="px-3 py-4 text-center">{{ __("Arrivée") }}</th>
                                <th class="px-3 py-4 text-center">{{ __("Fin") }}</th>
                                <th class="px-3 py-4 text-center">{{ __("Vide sanitaire") }}</th>
                                <th class="px-3 py-4 text-center">{{ __("Commander") }}</th>
                                <th class="px-3 py-4 text-center">{{ __("Statut") }}</th>
                                <th class="px-5 py-4 text-center">{{ __("Actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($plans as $plan)
                            <tr @class(['hover:bg-slate-50/50 transition-all',
                                'bg-red-50/40' => $plan->is_overdue,
                                'bg-emerald-50/30' => $plan->status === 'en_cours'])>

                                <td class="px-5 py-4">
                                    <p class="text-xs font-black text-slate-900 uppercase">{{ $plan->building->name ?? '—' }}</p>
                                    <p class="text-[8px] text-slate-400">{{ $plan->building->type ?? '' }} — {{ $plan->building->capacity ?? '' }} {{ __("places") }}</p>
                                </td>

                                <td class="px-3 py-4">
                                    <span @class(['text-[8px] font-black uppercase px-2.5 py-1 rounded-full inline-block',
                                        'bg-blue-50 text-blue-600' => $plan->batch_type === 'chair',
                                        'bg-amber-50 text-amber-600' => $plan->batch_type === 'ponte',
                                        'bg-pink-50 text-pink-600' => $plan->batch_type === 'poussiniere',
                                        'bg-purple-50 text-purple-600' => $plan->batch_type === 'reproducteur'])>
                                        {{ $plan->batch_type }}
                                    </span>
                                    @if($plan->model_name)
                                        <p class="text-[8px] text-slate-400 mt-0.5">{{ $plan->model_name }}</p>
                                    @endif
                                </td>

                                <td class="px-3 py-4 text-center text-sm font-black text-slate-900">{{ number_format($plan->planned_quantity) }}</td>

                                <td class="px-3 py-4 text-center">
                                    <p class="text-[10px] font-black {{ $plan->days_until_arrival <= 7 && $plan->days_until_arrival > 0 ? 'text-amber-600' : 'text-slate-600' }}">
                                        {{ $plan->planned_arrival_date->format('d/m/Y') }}
                                    </p>
                                    @if($plan->days_until_arrival > 0 && !in_array($plan->status, ['termine', 'annule', 'en_cours']))
                                        <p class="text-[8px] {{ $plan->days_until_arrival <= 3 ? 'text-red-500 font-black' : 'text-slate-400' }}">{{ __("dans :countj", ['count' => $plan->days_until_arrival]) }}</p>
                                    @endif
                                </td>

                                <td class="px-3 py-4 text-center text-[10px] font-black text-slate-500">{{ $plan->planned_end_date->format('d/m/Y') }}</td>

                                <td class="px-3 py-4 text-center text-[9px] font-black text-slate-400">
                                    @if($plan->sanitary_void_end)
                                        {{ $plan->sanitary_void_start->format('d/m') }} → {{ $plan->sanitary_void_end->format('d/m') }}
                                    @else — @endif
                                </td>

                                <td class="px-3 py-4 text-center">
                                    @if($plan->chick_order_deadline)
                                        <p class="text-[10px] font-black {{ $plan->is_overdue ? 'text-red-600' : 'text-slate-600' }}">
                                            {{ $plan->chick_order_deadline->format('d/m/Y') }}
                                        </p>
                                        @if($plan->is_overdue)
                                            <p class="text-[8px] text-red-500 font-black animate-pulse">{{ __("⚠ EN RETARD") }}</p>
                                        @endif
                                    @endif
                                </td>

                                <td class="px-3 py-4 text-center">
                                    <span @class(['text-[8px] font-black uppercase px-2.5 py-1 rounded-full',
                                        'bg-slate-100 text-slate-500' => $plan->status === 'planifie',
                                        'bg-blue-100 text-blue-600' => $plan->status === 'commande',
                                        'bg-emerald-100 text-emerald-600' => $plan->status === 'en_cours',
                                        'bg-slate-800 text-white' => $plan->status === 'termine',
                                        'bg-red-100 text-red-500' => $plan->status === 'annule'])>
                                        {{ $plan->status }}
                                    </span>
                                    @if($plan->actual_batch_id)
                                        <p class="text-[7px] text-emerald-500 mt-0.5">{{ __("LOT LIÉ") }}</p>
                                    @endif
                                </td>

                                {{-- ACTIONS RAPIDES --}}
                                <td class="px-5 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        {{-- Voir --}}
                                        <a href="{{ route('planning.show', $plan) }}" class="text-slate-300 hover:text-indigo-600 no-underline" title="{{ __("Détail") }}"><i class="fa-solid fa-eye"></i></a>

                                        @can('planning.M')
                                        {{-- Marquer commandé --}}
                                        @if($plan->status === 'planifie')
                                            <form method="POST" action="{{ route('planning.status', $plan) }}" class="inline">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="status" value="commande">
                                                <button type="submit" class="text-blue-400 hover:text-blue-600 border-none bg-transparent cursor-pointer" title="{{ __("Marquer Commandé") }}"><i class="fa-solid fa-phone"></i></button>
                                            </form>
                                        @endif

                                        {{-- Activer (lancer la bande) --}}
                                        @if(in_array($plan->status, ['planifie', 'commande']) && !$plan->actual_batch_id)
                                            <a href="{{ route('planning.activate', $plan) }}" class="text-emerald-400 hover:text-emerald-600 no-underline" title="{{ __("Activer → Créer le lot") }}">
                                                <i class="fa-solid fa-rocket"></i>
                                            </a>
                                        @endif

                                        {{-- Voir le lot lié --}}
                                        @if($plan->actual_batch_id)
                                            <a href="{{ route('batches.show', $plan->actual_batch_id) }}" class="text-emerald-500 hover:text-emerald-700 no-underline" title="{{ __("Voir le lot") }}">
                                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                            </a>
                                        @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-calendar-xmark text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black mb-3">{{ __("Aucune bande planifiée sur cette période") }}</p>
                                    @can('planning.C')
                                    <a href="{{ route('planning.create') }}" class="bg-indigo-500 text-white px-6 py-3 rounded-xl font-black text-[9px] uppercase tracking-widest no-underline hover:bg-indigo-600 transition-all">
                                        <i class="fa-solid fa-plus mr-1"></i> {{ __("Planifier la première bande") }}
                                    </a>
                                    @endcan
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- LÉGENDE --}}
            <div class="mt-6 flex flex-wrap gap-4 justify-center">
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-eye text-slate-300"></i> {{ __("Détail") }}</span>
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-phone text-blue-400"></i> {{ __("Marquer commandé") }}</span>
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-rocket text-emerald-400"></i> {{ __("Activer & Créer le lot") }}</span>
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-arrow-up-right-from-square text-emerald-500"></i> {{ __("Voir le lot lié") }}</span>
            </div>
        </div>
    </div>
</x-app-layout>
