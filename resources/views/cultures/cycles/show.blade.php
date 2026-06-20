<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-leaf text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $cycle->crop_name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">
                        {{ $cycle->plot?->name }} · {{ $cycle->variety ?: __('Cycle de culture') }}
                    </p>
                </div>
            </div>
            <a href="{{ route('crop-cycles.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Retour") }}
            </a>
        </div>

        {{-- BARRE D'ACTIONS --}}
        <div class="flex flex-wrap gap-2 mt-4">
            @can('cultures.C')
            @unless($cycle->isArchived())
            <a href="{{ route('crop-cycles.harvests.create', $cycle) }}" class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-500 transition-all shadow-lg italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-wheat-awn text-amber-400"></i> {{ __("Saisir une récolte") }}
            </a>
            <a href="{{ route('crop-cycles.inputs.create', $cycle) }}" class="bg-white border border-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-lime-50 hover:text-lime-700 transition-all italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-flask text-lime-500"></i> {{ __("Ajouter un intrant") }}
            </a>
            @endunless
            @endcan
            @can('cultures.M')
            <a href="{{ route('crop-cycles.edit', $cycle) }}" class="bg-white border border-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-50 transition-all italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-pen text-green-500"></i> {{ __("Modifier le cycle") }}
            </a>
            @endcan
            @can('cultures.S')
            @if(!$cycle->harvests->count())
            <form action="{{ route('crop-cycles.destroy', $cycle) }}" method="POST" onsubmit="return confirm('Supprimer ce cycle définitivement ?')" class="inline">
                @csrf @method('DELETE')
                <button class="bg-white border border-rose-100 text-rose-500 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-50 transition-all italic flex items-center gap-2"><i class="fa-solid fa-trash"></i>{{ __("Supprimer") }}</button>
            </form>
            @endif
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="p-5 bg-rose-600 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg"></i> {{ session('error') }}
                </div>
            @endif

            {{-- INDICATEURS --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Âge") }}</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ $cycle->age }} <small class="text-[10px] opacity-40">j</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Récolté") }}</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($cycle->total_harvested, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Rendement/ha") }}</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($cycle->yield_per_ha, 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg/ha</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Marge nette") }}</p>
                    <p class="text-2xl font-black leading-none {{ $cycle->net_margin >= 0 ? '' : 'text-rose-400' }}">{{ number_format($cycle->net_margin, 0, ',', ' ') }} <small class="text-[10px] opacity-40">GNF</small></p>
                </div>
            </div>

            {{-- FICHE TECHNIQUE & ÉCONOMIQUE --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- Fiche technique --}}
                <div class="lg:col-span-2 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Fiche technique") }}</h3>
                        @php $stColor = $cycle->status === 'en_cours' ? 'green' : ($cycle->status === 'recolte' ? 'amber' : 'slate'); @endphp
                        <span class="text-[8px] font-black uppercase bg-{{ $stColor }}-100 text-{{ $stColor }}-700 px-3 py-1 rounded-full italic">{{ ucfirst(str_replace('_', ' ', $cycle->status)) }}</span>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Parcelle") }}</p><p class="text-[11px] font-black text-slate-800 italic">@if($cycle->plot)<a href="{{ route('plots.show', $cycle->plot) }}" class="text-green-600 no-underline">{{ $cycle->plot->name }}</a>@else—@endif</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Surface emblavée") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ number_format($cycle->area_used_ha, 2, ',', ' ') }} ha</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Campagne") }}</p>
                            <p class="text-[11px] font-black text-slate-800 italic">@if($cycle->campaign)<a href="{{ route('crop-campaigns.show', $cycle->campaign) }}" class="text-green-600 no-underline">{{ $cycle->campaign->name }}</a>@else—@endif</p>
                        </div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Semis") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->planting_date?->format('d/m/Y') ?? '—' }}</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Récolte prévue") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->expected_harvest_date?->format('d/m/Y') ?? '—' }}</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Responsable") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->employee ? $cycle->employee->first_name.' '.$cycle->employee->last_name : '—' }}</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Semences") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->seed_quantity ? number_format($cycle->seed_quantity, 2, ',', ' ').' '.$cycle->seed_unit : '—' }}</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Variété") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->variety ?: '—' }}</p></div>
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Code") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->code ?: '—' }}</p></div>
                    </div>

                    {{-- Avancement vers le rendement attendu --}}
                    @if($cycle->expected_yield_kg > 0)
                        @php $progress = min(100, round($cycle->total_harvested / $cycle->expected_yield_kg * 100)); @endphp
                        <div class="mt-6 pt-6 border-t border-slate-50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Récolté vs attendu") }}</span>
                                <span class="text-[10px] font-black text-green-600 italic">{{ number_format($cycle->total_harvested, 0, ',', ' ') }} / {{ number_format($cycle->expected_yield_kg, 0, ',', ' ') }} kg ({{ $progress }}%)</span>
                            </div>
                            <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full {{ $progress >= 90 ? 'bg-green-500' : 'bg-amber-400' }} rounded-full" style="width: {{ $progress }}%"></div>
                            </div>
                        </div>
                    @endif

                    @if($cycle->notes)
                        <div class="mt-4 text-[11px] text-slate-600 not-italic bg-slate-50 p-4 rounded-2xl">{{ $cycle->notes }}</div>
                    @endif
                </div>

                {{-- Structure de coûts --}}
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-5">{{ __("Structure des coûts") }}</h3>
                    @php
                        $acq = (float) $cycle->total_acquisition_cost;
                        $add = (float) $cycle->additional_costs;
                        $inp = (float) $cycle->inputs_cost;
                        $totalCost = $acq + $add + $inp;
                    @endphp
                    <div class="space-y-3 text-[11px]">
                        <div class="flex justify-between"><span class="text-slate-400 uppercase italic">{{ __("Semences/intrants init.") }}</span><span class="font-black text-slate-700">{{ number_format($acq, 0, ',', ' ') }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-400 uppercase italic">{{ __("Coûts additionnels") }}</span><span class="font-black text-slate-700">{{ number_format($add, 0, ',', ' ') }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-400 uppercase italic">{{ __("Intrants itémisés") }}</span><span class="font-black text-slate-700">{{ number_format($inp, 0, ',', ' ') }}</span></div>
                        <div class="flex justify-between pt-3 border-t border-slate-100"><span class="text-slate-500 uppercase italic font-black">{{ __("Total coûts") }}</span><span class="font-black text-rose-500">{{ number_format($totalCost, 0, ',', ' ') }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500 uppercase italic font-black">{{ __("Revenus") }}</span><span class="font-black text-green-600">{{ number_format($cycle->total_revenue, 0, ',', ' ') }}</span></div>
                        <div class="flex justify-between pt-3 border-t border-slate-100"><span class="text-slate-700 uppercase italic font-black">{{ __("Marge nette") }}</span><span class="font-black {{ $cycle->net_margin >= 0 ? 'text-green-600' : 'text-rose-500' }}">{{ number_format($cycle->net_margin, 0, ',', ' ') }}</span></div>
                    </div>
                </div>
            </div>

            {{-- JOURNAL DES RÉCOLTES --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Journal des récoltes") }}</h3>
                    @can('cultures.C')
                    @unless($cycle->isArchived())
                    <a href="{{ route('crop-cycles.harvests.create', $cycle) }}" class="text-[9px] font-black uppercase text-amber-600 hover:text-amber-700 italic no-underline"><i class="fa-solid fa-plus mr-1"></i>{{ __("Récolte") }}</a>
                    @endunless
                    @endcan
                </div>
                @forelse($cycle->harvests->sortByDesc('harvest_date') as $h)
                    <div class="flex items-center justify-between py-3 border-b border-slate-50">
                        <div>
                            <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ number_format($h->quantity, 0, ',', ' ') }} {{ $h->unit }}
                                <span class="text-[8px] px-2 py-0.5 rounded-full ml-2 {{ $h->quality === 'bon' ? 'bg-green-50 text-green-600' : ($h->quality === 'moyen' ? 'bg-amber-50 text-amber-600' : 'bg-rose-50 text-rose-600') }}">{{ ucfirst($h->quality) }}</span>
                                @if($h->synced_to_stock)<i class="fa-solid fa-warehouse text-blue-400 ml-1 text-[9px]" title="{{ __('Intégré au stock') }}"></i>@endif
                            </p>
                            <p class="text-[8px] text-slate-400 uppercase mt-1">{{ $h->harvest_date?->format('d/m/Y') }}
                                @if($h->employee) · {{ $h->employee->first_name }} {{ $h->employee->last_name }}@endif
                                @if($h->loss_quantity > 0) · {{ __("pertes") }} {{ number_format($h->loss_quantity, 0, ',', ' ') }}@endif
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            @if($h->unit_price)<p class="text-[10px] font-black text-slate-500">{{ number_format($h->estimated_value, 0, ',', ' ') }} GNF</p>@endif
                            @can('cultures.M')
                            <a href="{{ route('crop-cycles.harvests.edit', [$cycle, $h]) }}" class="text-slate-300 hover:text-green-600 text-xs no-underline"><i class="fa-solid fa-pen-to-square"></i></a>
                            @endcan
                            @can('cultures.S')
                            <form action="{{ route('crop-cycles.harvests.destroy', [$cycle, $h]) }}" method="POST" onsubmit="return confirm('Supprimer cette récolte ?')">
                                @csrf @method('DELETE')
                                <button class="text-rose-300 hover:text-rose-600 text-xs"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-10">{{ __("Aucune récolte enregistrée") }}</p>
                @endforelse
            </div>

            {{-- REGISTRE DES INTRANTS --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Registre des intrants") }}</h3>
                    <div class="flex items-center gap-4">
                        <span class="text-[10px] font-black text-rose-500 italic">{{ number_format($cycle->inputs_cost, 0, ',', ' ') }} GNF</span>
                        @can('cultures.C')
                        @unless($cycle->isArchived())
                        <a href="{{ route('crop-cycles.inputs.create', $cycle) }}" class="text-[9px] font-black uppercase text-lime-600 hover:text-lime-700 italic no-underline"><i class="fa-solid fa-plus mr-1"></i>{{ __("Intrant") }}</a>
                        @endunless
                        @endcan
                    </div>
                </div>
                @forelse($cycle->inputs->sortByDesc('input_date') as $in)
                    <div class="flex items-center justify-between py-3 border-b border-slate-50">
                        <div>
                            <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $in->name }}
                                <span class="text-[8px] px-2 py-0.5 rounded-full ml-2 bg-lime-50 text-lime-600">{{ $in->type_label }}</span>
                                @if($in->synced_to_stock)<i class="fa-solid fa-warehouse text-blue-400 ml-1 text-[9px]" title="{{ __('Intégré au stock') }}"></i>@endif
                            </p>
                            <p class="text-[8px] text-slate-400 uppercase mt-1">{{ $in->input_date?->format('d/m/Y') }}
                                @if($in->quantity > 0) · {{ number_format($in->quantity, 0, ',', ' ') }} {{ $in->unit }}@endif
                                @if($in->provider) · {{ $in->provider->name }}@endif
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            <p class="text-[10px] font-black text-slate-700">{{ number_format($in->total_cost, 0, ',', ' ') }} GNF</p>
                            @can('cultures.M')
                            <a href="{{ route('crop-cycles.inputs.edit', [$cycle, $in]) }}" class="text-slate-300 hover:text-lime-600 text-xs no-underline"><i class="fa-solid fa-pen-to-square"></i></a>
                            @endcan
                            @can('cultures.S')
                            <form action="{{ route('crop-cycles.inputs.destroy', [$cycle, $in]) }}" method="POST" onsubmit="return confirm('Supprimer cet intrant ?')">
                                @csrf @method('DELETE')
                                <button class="text-rose-300 hover:text-rose-600 text-xs"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-10">{{ __("Aucun intrant enregistré") }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
