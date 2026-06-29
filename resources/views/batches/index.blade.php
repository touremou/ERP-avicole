<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Suivi des Bandes')" :subtitle="__('Gestion des cycles de production en cours')" icon="fa-microchip" accent="indigo">
            <x-slot name="actions">
                {{-- PERMISSION L : ACCÈS AUX ARCHIVES ET NORMES --}}
                @can('elevage.L')
                <a href="{{ route('daily-checks.index') }}" class="bg-white text-slate-600 border border-slate-200 px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-50 transition-all shadow-sm flex items-center italic">
                    <i class="fas fa-clock-rotate-left mr-2 text-blue-500"></i>
                    {{ __("Historique Suivi") }}
                </a>
                <a href="{{ route('batches.archives') }}" class="bg-white text-slate-600 border border-slate-200 px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-50 transition-all shadow-sm flex items-center italic">
                    <i class="fas fa-book-open mr-2 text-blue-500"></i>
                    {{ __("Archives") }}
                </a>
                @endcan

                {{-- PERMISSION C : CRÉATION D'UN NOUVEAU LOT --}}
                @can('elevage.C')
                <a href="{{ route('batches.norms.index') }}" class="bg-white text-slate-600 border border-slate-200 px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-50 transition-all shadow-sm flex items-center italic">
                    <i class="fas fa-scroll mr-2 text-blue-500"></i>
                    {{ __("Référentiel Normes") }}
                </a>
                <a href="{{ route('batches.create') }}" class="group bg-slate-900 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/20 flex items-center italic">
                    <i class="fas fa-plus-circle mr-2 group-hover:rotate-90 transition-transform text-sm"></i>
                    {{ __("Nouvel Arrivage") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-12 italic" >
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- FILTRES PAR FAMILLE D'ESPÈCE --}}
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <a href="{{ route('batches.index') }}"
                   @class([
                       'px-6 py-3 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all shadow-sm border',
                       'bg-slate-900 text-white border-slate-900' => !$familyFilter,
                       'bg-white text-slate-400 border-slate-100 hover:bg-slate-50' => $familyFilter,
                   ])>
                    <i class="fa-solid fa-globe mr-2"></i> {{ __("Toutes espèces") }}
                </a>
                @foreach([
                    'volaille'    => ['label' => 'Volaille',          'icon' => '🐔', 'color' => 'amber'],
                    'ruminants'   => ['label' => 'Ovins / Caprins',   'icon' => '🐑', 'color' => 'sky'],
                    'aquaculture' => ['label' => 'Pisciculture',      'icon' => '🐟', 'color' => 'blue'],
                    'autres'      => ['label' => 'Porcins / Lapins',  'icon' => '🐷', 'color' => 'rose'],
                ] as $group => $meta)
                    @if(($familyCounts[$group] ?? 0) > 0 || $familyFilter === $group)
                    <a href="{{ route('batches.index', ['family' => $group]) }}"
                       @class([
                           "px-6 py-3 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all shadow-sm border",
                           "bg-{$meta['color']}-500 text-white border-{$meta['color']}-500" => $familyFilter === $group,
                           "bg-white text-{$meta['color']}-600 border-{$meta['color']}-100 hover:bg-{$meta['color']}-50" => $familyFilter !== $group,
                       ])>
                        <span class="mr-1">{{ $meta['icon'] }}</span> {{ __($meta['label']) }} ({{ $familyCounts[$group] ?? 0 }})
                    </a>
                    @endif
                @endforeach
            </div>

            {{-- FILTRES DE TYPE (sous-filtre de la famille Volaille uniquement) --}}
            {{-- Les types chair/ponte/repro/poussinière sont propres à la volaille : --}}
            {{-- on ne les affiche que dans ce contexte pour que « Tous » et les --}}
            {{-- compteurs de type s'additionnent (sinon les lots non-volaille --}}
            {{-- gonflent « Tous » sans onglet correspondant). --}}
            @if($familyFilter === 'volaille')
            <div class="flex flex-wrap items-center gap-3 mb-8" id="batchContainer">
                <a href="{{ route('batches.index', ['family' => $familyFilter]) }}"
                   @class([
                       'px-6 py-3 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all shadow-sm border',
                       'bg-slate-900 text-white border-slate-900' => !request('type'),
                       'bg-white text-slate-400 border-slate-100 hover:bg-slate-50' => request('type')
                   ])>
                    <i class="fa-solid fa-layer-group mr-2"></i> {{ __("Tous") }} ({{ $counts['all'] ?? 0 }})
                </a>
                @foreach(['chair' => 'orange', 'ponte' => 'blue', 'reproducteur' => 'emerald', 'poussiniere' => 'purple'] as $type => $color)
                    <a href="{{ route('batches.index', ['type' => $type, 'family' => $familyFilter]) }}"
                       @class([
                           "px-6 py-3 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all shadow-sm border",
                           "bg-{$color}-500 text-white border-{$color}-500 shadow-{$color}-200" => request('type') == $type,
                           "bg-white text-{$color}-600 border-{$color}-100 hover:bg-{$color}-50" => request('type') != $type
                       ])>
                        <i class="fa-solid {{ $type == 'chair' ? 'fa-drumstick-bite' : ($type == 'ponte' ? 'fa-egg' : ($type == 'reproducteur' ? 'fa-dna' : 'fa-baby-carriage')) }} mr-2"></i>
                        {{ ucfirst($type) }} ({{ $counts[$type] ?? 0 }})
                    </a>
                @endforeach
            </div>
            @endif

            <div class="bg-white rounded-[3rem] shadow-sm border border-slate-100 overflow-hidden font-bold text-left">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100 font-black italic uppercase text-slate-400 text-[9px] tracking-widest">
                            <th class="px-8 py-5">{{ __("Identité & Souche") }}</th>
                            <th class="hidden md:table-cell px-6 py-5">{{ __("Responsable") }}</th>
                            <th class="hidden md:table-cell px-6 py-5 text-center">{{ __("Bâtiment") }}</th>
                            <th class="px-6 py-5 text-center">{{ __("Vivant actuel") }}</th>
                            <th class="hidden lg:table-cell px-6 py-5 text-center">{{ __("Santé") }}</th>
                            <th class="px-6 py-5 text-center">{{ __("Cycle de vie") }}</th>
                            <th class="px-8 py-5 text-right">{{ __("Actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($batches as $batch)
                        @php
                            $arrival = \Carbon\Carbon::parse($batch->arrival_date)->startOfDay();
                            $days = (int) $arrival->diffInDays(now()->startOfDay());
                            $maxDays = $batch->productionType?->cycle_days_default ?? match($batch->type) {
                                'chair' => 45, 'ponte' => 540, 'poussiniere' => 140, 'reproducteur' => 450, default => 45,
                            };
                            $percent = min(round(($days / $maxDays) * 100), 100);
                            $survivalRate = $batch->initial_quantity > 0 ? ($batch->current_quantity / $batch->initial_quantity) * 100 : 100;
                        @endphp

                        <tr class="group hover:bg-slate-50/50 transition-all relative italic">
                            <td class="px-8 py-6">
                                <div class="flex items-start">
                                    <div @class([
                                        'w-10 h-10 rounded-xl flex items-center justify-center mr-4 shadow-inner mt-1 text-base',
                                        'bg-blue-50 text-blue-600 font-black' => $batch->isActive(),
                                        'bg-slate-100 text-slate-400' => ! $batch->isActive(),
                                    ])>
                                        @if($batch->species?->icon)
                                            {{ $batch->species->icon }}
                                        @else
                                            <i class="fas fa-dove"></i>
                                        @endif
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-800 uppercase tracking-tighter leading-none mb-1 text-sm">{{ $batch->code }}</p>
                                        <div class="flex gap-1 items-center">
                                            <span @class([
                                                'text-[7px] font-black px-2 py-0.5 rounded uppercase italic border',
                                                'bg-orange-50 text-orange-600 border-orange-100' => $batch->type == 'chair',
                                                'bg-blue-50 text-blue-600 border-blue-100' => $batch->type == 'ponte',
                                                'bg-emerald-50 text-emerald-600 border-emerald-100' => $batch->type == 'reproducteur',
                                                'bg-purple-50 text-purple-600 border-purple-100' => $batch->type == 'poussiniere',
                                                'bg-slate-50 text-slate-500 border-slate-100' => !in_array($batch->type, ['chair','ponte','reproducteur','poussiniere']),
                                            ])>{{ $batch->productionType?->name_fr ?? $batch->type }}</span>
                                            <span class="text-[7px] font-black px-2 py-0.5 rounded uppercase italic border bg-slate-800 text-white border-slate-800">
                                                <i class="fas fa-fingerprint mr-1 text-[6px]"></i> {{ $batch->model_name ?? __("Standard") }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="hidden md:table-cell px-6 py-6 font-bold text-xs uppercase text-slate-600">
                                {{ $batch->employee ? $batch->employee->first_name . ' ' . substr($batch->employee->last_name, 0, 1) . '.' : 'N/A' }}
                            </td>

                            <td class="hidden md:table-cell px-6 py-6 text-center font-black text-xs uppercase text-slate-400">
                                <i class="fas fa-warehouse mr-1 opacity-30"></i> {{ $batch->building->name ?? '---' }}
                            </td>

                            <td class="px-6 py-6 text-center">
                                <span @class([
                                    'font-black text-xl tracking-tighter',
                                    'text-slate-800' => $survivalRate >= 95,
                                    'text-orange-500' => $survivalRate < 95 && $survivalRate >= 90,
                                    'text-red-600' => $survivalRate < 90,
                                ])>
                                    {{ number_format($batch->current_quantity) }}
                                </span>
                                <p class="text-[7px] text-slate-300 font-black uppercase tracking-widest mt-1 italic">{{ __("Sujets en vie") }}</p>
                            </td>

                            <td class="hidden lg:table-cell px-6 py-6 text-center uppercase italic">
                                <div class="inline-flex flex-col items-center">
                                    <span @class([
                                        'px-3 py-1 rounded-lg text-[8px] font-black tracking-widest border mb-1',
                                        'bg-green-50 text-green-600 border-green-100' => $survivalRate >= 95,
                                        'bg-red-50 text-red-600 border-red-100' => $survivalRate < 95,
                                    ])>
                                        {{ number_format($survivalRate, 1) }}% {{ __("VIABILITÉ") }}
                                    </span>
                                    <span class="text-[6px] font-black text-slate-400">{{ __("LOT") }} {{ $batch->status }}</span>
                                </div>
                            </td>

                            <td class="px-6 py-6">
                                <div class="w-full flex flex-col items-center">
                                    <div class="w-32 flex justify-between text-[8px] font-black uppercase text-slate-400 mb-1 italic">
                                        <span>J+{{ $days }}</span>
                                        <span>{{ (int)$percent }}%</span>
                                    </div>
                                    <div class="w-32 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full {{ $percent >= 90 ? 'bg-red-500' : ($percent >= 70 ? 'bg-orange-400' : 'bg-blue-500') }}" style="width: {{ $percent }}%"></div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-8 py-6 text-right">
                                <div class="flex justify-end gap-2">
                                    {{-- LECTURE TOUJOURS DISPONIBLE SI AUTORISÉE --}}
                                    @can('elevage.L')
                                    <a href="{{ route('batches.show', $batch->id) }}" class="p-3 bg-white border border-slate-200 text-slate-400 hover:text-blue-500 hover:border-blue-200 rounded-xl transition-all shadow-sm">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                    </a>
                                    @endcan

                                    {{-- PERMISSION M : MODIFICATION DU LOT --}}
                                    @can('elevage.M')
                                    <a href="{{ route('batches.edit', $batch->id) }}" class="p-3 bg-white border border-slate-200 text-slate-400 hover:text-amber-500 hover:border-amber-200 rounded-xl transition-all shadow-sm">
                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-8 py-24 text-center">
                                <i class="fas fa-layer-group text-slate-200 text-3xl mb-4"></i>
                                <p class="text-slate-300 font-black uppercase tracking-[0.3em] text-[10px] italic">{{ __("Aucun lot actif trouvé") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>