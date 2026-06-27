<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-slate-900 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3">
                <i class="fa-solid fa-gears text-lg"></i>
            </div>
            <div class="text-left">
                <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Pilotage Provenderie") }}</h2>
                <p class="text-[10px] font-bold text-blue-600 uppercase tracking-widest mt-1 italic leading-none">{{ __("Flux de transformation & Stocks finis") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">

            {{-- ACCÈS GROUPÉS (hub-cartes) : toutes les sous-sections du module,
                 pour que le breadcrumb puisse rester « Tableau de bord » seul. --}}
            @can('provenderie.L')
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm not-italic">
                <p class="text-[10px] font-black uppercase tracking-widest text-lime-600 mb-4">{{ __("Atelier") }}</p>
                <div class="grid grid-cols-3 gap-3">
                    @foreach([
                        ['label' => 'Matières premières', 'icon' => 'fa-wheat-awn', 'route' => 'raw-materials.index'],
                        ['label' => 'Formules', 'icon' => 'fa-flask', 'route' => 'formulas.index'],
                        ['label' => 'Production', 'icon' => 'fa-industry', 'route' => 'production.index'],
                    ] as $it)
                        @if(\Illuminate\Support\Facades\Route::has($it['route']))
                        <a href="{{ route($it['route']) }}" class="flex flex-col items-center justify-center gap-2 p-4 bg-slate-50 rounded-2xl hover:bg-lime-50 hover:text-lime-600 transition-all no-underline text-slate-600 text-center">
                            <i class="fa-solid {{ $it['icon'] }} text-lg"></i>
                            <span class="text-[8px] font-black uppercase tracking-widest leading-tight">{{ __($it['label']) }}</span>
                        </a>
                        @endif
                    @endforeach
                </div>
            </div>
            @endcan

            {{-- KPI ROW --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="absolute -right-2 -top-2 opacity-5 text-slate-900 group-hover:scale-110 transition-transform"><i class="fa-solid fa-vault text-6xl"></i></div>
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 italic leading-none">{{ __("Trésorerie Matières") }}</p>
                    <p class="text-3xl font-black text-slate-900 tracking-tighter italic leading-none">
                        {{ number_format($rawMaterialsValue, 0, ',', ' ') }} <small class="text-[10px] opacity-40">{{ currency() }}</small>
                    </p>
                </div>

                <div @class([
                    'p-8 rounded-[3rem] border shadow-sm transition-all',
                    'bg-red-50 border-red-100' => $lowStockAlerts->count() > 0,
                    'bg-white border-slate-100' => $lowStockAlerts->count() == 0
                ])>
                    <p @class([
                        'text-[9px] font-black uppercase tracking-widest mb-2 italic leading-none',
                        'text-red-500' => $lowStockAlerts->count() > 0,
                        'text-slate-400' => $lowStockAlerts->count() == 0
                    ])>{{ __("Alertes Ingrédients") }}</p>
                    <p class="text-3xl font-black text-slate-900 tracking-tighter italic leading-none">{{ $lowStockAlerts->count() }}</p>
                </div>

                <div class="bg-slate-900 p-8 rounded-[3rem] shadow-2xl text-white relative overflow-hidden group">
                    <div class="absolute -right-2 -top-2 opacity-10 text-blue-400 group-hover:rotate-12 transition-transform"><i class="fa-solid fa-warehouse text-6xl"></i></div>
                    <p class="text-[9px] font-black text-blue-400 uppercase tracking-widest mb-2 italic leading-none">{{ __("Aliment Fini (Total)") }}</p>
                    <p class="text-3xl font-black tracking-tighter italic leading-none">
                        {{ number_format($finishedFeeds->sum('current_quantity'), 0, ',', ' ') }} <small class="text-[10px] opacity-40 italic">KG</small>
                    </p>
                </div>

                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm relative overflow-hidden group">
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic leading-none">{{ __("Volume ce mois") }}</p>
                    <p class="text-3xl font-black text-slate-900 tracking-tighter italic leading-none">
                        {{ number_format($monthlyVolume, 0, ',', ' ') }} <small class="text-[10px] opacity-40 italic">KG</small>
                    </p>
                    <p class="text-[8px] text-slate-400 mt-2 uppercase italic font-black">{{ $totalFormulaCount }} {{ __("formules actives") }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
                {{-- SILOS DE CONSOMMATION --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="flex items-center justify-between px-6">
                        <h3 class="text-xs font-black uppercase text-slate-800 tracking-tighter italic leading-none">{{ __("État des Silos de Consommation") }}</h3>
                        <span class="text-[8px] text-slate-400 uppercase font-bold italic">{{ __("Unité : Kilogramme") }}</span>
                    </div>
                    
                    <div class="bg-white rounded-[3.5rem] border border-slate-100 shadow-sm overflow-hidden">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100">
                                    <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none">{{ __("Aliment Fini") }}</th>
                                    <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none text-center">{{ __("Sacs (50kg)") }}</th>
                                    <th class="p-6 text-[10px] font-black uppercase tracking-widest italic text-slate-400 leading-none text-right px-10">{{ __("Stock (kg)") }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @forelse($finishedFeeds as $feed)
                                <tr class="hover:bg-slate-50/80 transition-colors group">
                                    <td class="p-6">
                                        @php
                                            // Couleur du silo selon le secteur d'aliment (multiespèces).
                                            // Le nom de l'article commence par le mot du secteur
                                            // (ex: « Laitière Production »), cf. Batch::FEED_PHASES.
                                            $sectorColors = [
                                                'Chair' => 'bg-slate-900', 'Ponte' => 'bg-emerald-500',
                                                'Reproducteur' => 'bg-emerald-500', 'Engraissement' => 'bg-orange-500',
                                                'Laitière' => 'bg-blue-500', 'Grossissement' => 'bg-cyan-500',
                                                'Alevinage' => 'bg-cyan-400',
                                            ];
                                            $feedSector = collect(array_keys(\App\Models\Batch::FEED_PHASES))
                                                ->first(fn ($s) => \Illuminate\Support\Str::startsWith($feed->item_name, $s));
                                            $dotColor = $sectorColors[$feedSector] ?? 'bg-blue-500';
                                        @endphp
                                        <div class="flex items-center gap-3">
                                            <div class="w-2 h-8 rounded-full transition-all group-hover:h-10 {{ $dotColor }}"></div>
                                            <div>
                                                <p class="text-sm font-black text-slate-800 uppercase italic leading-none">{{ $feed->item_name }}</p>
                                                <p class="text-[8px] text-slate-400 uppercase mt-1 italic tracking-widest font-bold">{{ $feed->category }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-6 text-center">
                                        <div class="inline-block px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black text-slate-600 italic">
                                            {{ number_format($feed->current_quantity / 50, 1) }}
                                        </div>
                                    </td>
                                    <td class="p-6 text-right px-10">
                                        <p @class([
                                            'text-2xl font-black tracking-tighter italic leading-none',
                                            'text-blue-600' => $feed->current_quantity > ($feed->alert_threshold ?? 0),
                                            'text-red-500 animate-pulse' => $feed->current_quantity <= ($feed->alert_threshold ?? 0)
                                        ])>
                                            {{ number_format($feed->current_quantity, 1, ',', ' ') }}
                                            <small class="text-[10px] uppercase text-slate-400 ml-1 italic">kg</small>
                                        </p>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="3" class="p-12 text-center text-slate-300 uppercase italic text-[10px] font-black">{{ __("Aucun aliment fini en stock") }}</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- ALERTES & NAVIGATION --}}
                <div class="space-y-8">
                    <h3 class="text-xs font-black uppercase text-slate-800 tracking-tighter ml-6 italic leading-none">{{ __("Alertes Matières Premières") }}</h3>
                    
                    <div class="bg-white p-8 rounded-[4rem] border border-slate-100 shadow-sm space-y-4">
                        @forelse($lowStockAlerts as $alert)
                        <div class="flex items-center gap-4 p-4 bg-red-50 rounded-3xl border border-red-100 group hover:bg-red-100 transition-colors">
                            <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center text-red-600 shadow-sm transition-transform group-hover:rotate-12">
                                <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                            </div>
                            <div class="text-left">
                                <p class="text-[9px] font-black text-slate-800 uppercase italic leading-none tracking-tighter">{{ $alert->name }}</p>
                                <div class="flex items-center gap-2 mt-1">
                                    <p class="text-[8px] text-red-500 uppercase font-black italic">{{ __("Seuil") }} : {{ number_format($alert->alert_threshold, 0) }}kg</p>
                                    <span class="w-1 h-1 bg-red-200 rounded-full"></span>
                                    <p class="text-[8px] text-red-600 font-black italic">{{ __("Actuel") }} : {{ number_format($alert->stock_qty, 1) }}kg</p>
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-10">
                            <div class="w-12 h-12 bg-emerald-50 rounded-full flex items-center justify-center text-emerald-500 mx-auto mb-4">
                                <i class="fa-solid fa-check-double"></i>
                            </div>
                            <p class="text-[9px] text-slate-400 uppercase italic tracking-widest font-black leading-tight">{{ __("Tous les silos") }}<br>{{ __("sont remplis") }}</p>
                        </div>
                        @endforelse
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                        @can('provenderie.C')
                        <a href="{{ route('production.create') }}" class="group bg-slate-900 p-8 rounded-[3rem] text-white hover:bg-blue-600 transition-all flex justify-between items-center italic shadow-2xl relative overflow-hidden no-underline">
                            <div class="relative z-10 text-left">
                                <p class="text-[8px] text-blue-400 uppercase font-black tracking-widest leading-none mb-1">{{ __("Production") }}</p>
                                <p class="text-xs font-black uppercase tracking-widest italic">{{ __("Lancer Mélange") }}</p>
                            </div>
                            <i class="fa-solid fa-play text-sm group-hover:translate-x-2 transition-transform relative z-10"></i>
                        </a>
                        @endcan

                        @can('provenderie.L')
                        <a href="{{ route('raw-materials.index') }}" class="group bg-white p-8 rounded-[3rem] text-slate-800 border border-slate-100 hover:border-emerald-200 transition-all flex justify-between items-center italic shadow-sm no-underline">
                            <div class="text-left">
                                <p class="text-[8px] text-emerald-500 uppercase font-black tracking-widest leading-none mb-1">{{ __("Inventaire") }}</p>
                                <p class="text-xs font-black uppercase tracking-widest italic">{{ __("Gestion Ingrédients") }}</p>
                            </div>
                            <i class="fa-solid fa-list-check text-sm text-slate-300 group-hover:text-emerald-500 transition-colors"></i>
                        </a>

                        <a href="{{ route('formulas.index') }}" class="group bg-white p-8 rounded-[3rem] text-slate-800 border border-slate-100 hover:border-blue-200 transition-all flex justify-between items-center italic shadow-sm no-underline">
                            <div class="text-left">
                                <p class="text-[8px] text-blue-500 uppercase font-black tracking-widest leading-none mb-1">{{ __("Bibliothèque") }}</p>
                                <p class="text-xs font-black uppercase tracking-widest italic">{{ __("Formules & Normes") }}</p>
                            </div>
                            <i class="fa-solid fa-flask-vial text-sm text-slate-300 group-hover:text-blue-500 transition-colors"></i>
                        </a>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
