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
            <div class="flex items-center gap-3">
                <a href="{{ route('crop-cycles.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Retour") }}
                </a>
                @can('cultures.S')
                @if(!$cycle->harvests->count())
                <form action="{{ route('crop-cycles.destroy', $cycle) }}" method="POST" onsubmit="return confirm('Supprimer ce cycle définitivement ?')">
                    @csrf @method('DELETE')
                    <button class="text-rose-400 hover:text-rose-600 text-[10px] font-black uppercase italic"><i class="fa-solid fa-trash mr-1"></i>{{ __("Supprimer") }}</button>
                </form>
                @endif
                @endcan
            </div>
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
                        <div><p class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Parcelle") }}</p><p class="text-[11px] font-black text-slate-800 italic">{{ $cycle->plot?->name ?? '—' }}</p></div>
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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- RÉCOLTES --}}
                <div class="lg:col-span-2 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Journal des récoltes") }}</h3>
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
                            <div class="flex items-center gap-3">
                                @if($h->unit_price)<p class="text-[10px] font-black text-slate-500">{{ number_format($h->estimated_value, 0, ',', ' ') }} GNF</p>@endif
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

                {{-- SAISIE RÉCOLTE --}}
                <div class="space-y-6">
                    @can('cultures.C')
                    @unless($cycle->isArchived())
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[10px] font-black uppercase text-green-500 tracking-widest italic mb-4">{{ __("Saisir une récolte") }}</h3>
                        <form action="{{ route('crop-cycles.harvests.store', $cycle) }}" method="POST" class="space-y-3" x-data="{ sync: false }">
                            @csrf
                            <div>
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date *") }}</label>
                                <input type="date" name="harvest_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                            </div>
                            <div class="flex gap-2">
                                <div class="w-2/3">
                                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité *") }}</label>
                                    <input type="number" step="0.001" min="0.001" name="quantity" required class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                                </div>
                                <div class="w-1/3">
                                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                                    <input type="text" name="unit" value="kg" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-center text-[11px]">
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <div class="w-1/2">
                                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Pertes") }}</label>
                                    <input type="number" step="0.001" min="0" name="loss_quantity" value="0" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                                </div>
                                <div class="w-1/2">
                                    <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Qualité") }}</label>
                                    <select name="quality" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] appearance-none">
                                        @foreach($qualities as $q)<option value="{{ $q }}">{{ ucfirst($q) }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Responsable") }}</label>
                                <select name="employee_id" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] appearance-none">
                                    <option value="">{{ __("-- Aucun --") }}</option>
                                    @foreach($employees as $emp)<option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Prix unitaire (GNF/u)") }}</label>
                                <input type="number" step="1" min="0" name="unit_price" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                            </div>
                            <label class="flex items-center gap-2 px-2 py-1 cursor-pointer">
                                <input type="hidden" name="sync_to_stock" value="0">
                                <input type="checkbox" name="sync_to_stock" value="1" x-model="sync" class="rounded">
                                <span class="text-[9px] font-black text-slate-500 uppercase italic">{{ __("Intégrer au stock (Récoltes)") }}</span>
                            </label>
                            <div x-show="sync" x-cloak>
                                <input type="text" name="stock_item_name" value="{{ $cycle->crop_name }}" placeholder="{{ __('Nom article stock') }}" class="w-full bg-blue-50 border-none rounded-xl p-3 font-black text-blue-800 shadow-inner italic text-[11px]">
                            </div>
                            <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-[1.5rem] font-black uppercase italic tracking-widest text-[10px] hover:bg-green-600 transition">
                                <i class="fa-solid fa-plus mr-2 text-green-400"></i> {{ __("Enregistrer") }}
                            </button>
                        </form>
                    </div>
                    @endunless
                    @endcan

                    {{-- GESTION DU CYCLE --}}
                    @can('cultures.M')
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4">{{ __("Pilotage du cycle") }}</h3>
                        <form action="{{ route('crop-cycles.update', $cycle) }}" method="POST" class="space-y-3">
                            @csrf @method('PUT')
                            <input type="hidden" name="crop_name" value="{{ $cycle->crop_name }}">
                            <input type="hidden" name="variety" value="{{ $cycle->variety }}">
                            <div>
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Statut") }}</label>
                                <select name="status" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] appearance-none">
                                    @foreach(\App\Models\CropCycle::EDITABLE_STATUSES as $s)
                                        <option value="{{ $s }}" @selected($cycle->status === $s)>{{ ucfirst($s) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Revenu total (GNF)") }}</label>
                                <input type="number" step="1" min="0" name="total_revenue" value="{{ (int) $cycle->total_revenue }}" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                            </div>
                            <div>
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coûts additionnels (GNF)") }}</label>
                                <input type="number" step="1" min="0" name="additional_costs" value="{{ (int) $cycle->additional_costs }}" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                            </div>
                            <input type="hidden" name="total_acquisition_cost" value="{{ (int) $cycle->total_acquisition_cost }}">
                            <button type="submit" class="w-full bg-slate-100 text-slate-700 py-3 rounded-[1.5rem] font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-200 transition">
                                {{ __("Mettre à jour") }}
                            </button>
                        </form>
                    </div>
                    @endcan
                </div>
            </div>

            {{-- INTRANTS & COÛTS --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Registre des intrants") }}</h3>
                        <span class="text-[10px] font-black text-rose-500 italic">{{ number_format($cycle->inputs_cost, 0, ',', ' ') }} GNF</span>
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
                            <div class="flex items-center gap-3">
                                <p class="text-[10px] font-black text-slate-700">{{ number_format($in->total_cost, 0, ',', ' ') }} GNF</p>
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

                @can('cultures.C')
                @unless($cycle->isArchived())
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-lime-600 tracking-widest italic mb-4">{{ __("Ajouter un intrant") }}</h3>
                    <form action="{{ route('crop-cycles.inputs.store', $cycle) }}" method="POST" class="space-y-3" x-data="{ q: 0, uc: 0, get total() { return this.q * this.uc } }">
                        @csrf
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type *") }}</label>
                            <select name="type" required class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] appearance-none">
                                @foreach($inputTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Libellé *") }}</label>
                            <input type="text" name="name" required placeholder="{{ __('NPK 15-15-15…') }}" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                        </div>
                        <div class="flex gap-2">
                            <div class="w-1/2">
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité") }}</label>
                                <input type="number" step="0.001" min="0" name="quantity" x-model.number="q" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                            </div>
                            <div class="w-1/2">
                                <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Unité") }}</label>
                                <input type="text" name="unit" value="kg" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-center text-[11px]">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût unitaire (GNF)") }}</label>
                            <input type="number" step="1" min="0" name="unit_cost" x-model.number="uc" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Coût total (GNF)") }}</label>
                            <input type="number" step="1" min="0" name="total_cost" :placeholder="total.toFixed(0)" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-right text-[11px]">
                            <p class="text-[7px] text-slate-300 uppercase mt-1 ml-2 italic">{{ __("Laisser vide = quantité × coût unitaire") }}</p>
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date *") }}</label>
                            <input type="date" name="input_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                        </div>
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Fournisseur") }}</label>
                            <select name="provider_id" class="w-full bg-slate-50 border-none rounded-xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] appearance-none">
                                <option value="">{{ __("-- Aucun --") }}</option>
                                @foreach($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </div>
                        <label class="flex items-center gap-2 px-2 py-1 cursor-pointer" x-data="{ s: false }">
                            <input type="hidden" name="synced_to_stock" value="0">
                            <input type="checkbox" name="synced_to_stock" value="1" x-model="s" class="rounded">
                            <span class="text-[9px] font-black text-slate-500 uppercase italic">{{ __("Entrer en stock (Intrants)") }}</span>
                        </label>
                        <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-[1.5rem] font-black uppercase italic tracking-widest text-[10px] hover:bg-lime-600 transition">
                            <i class="fa-solid fa-plus mr-2 text-lime-400"></i> {{ __("Enregistrer") }}
                        </button>
                    </form>
                </div>
                @endunless
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
