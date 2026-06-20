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
                            @if($h->unit_price)<p class="text-[10px] font-black text-slate-500">{{ number_format($h->estimated_value, 0, ',', ' ') }} GNF</p>@endif
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
        </div>
    </div>
</x-app-layout>
