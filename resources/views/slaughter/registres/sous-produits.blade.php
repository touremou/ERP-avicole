<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Registre des sous-produits')" :subtitle="__('E9 — Sang, plumes, viscères : volumes et destination')" icon="fa-recycle" accent="rose" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            @if($errors->any())
                <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
            @endif

            {{-- VOLUMES PAR TYPE (période filtrée) --}}
            @if($totals->isNotEmpty())
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                @foreach(\App\Models\SlaughterByproduct::TYPES as $key => $label)
                    <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black uppercase text-slate-400 tracking-widest m-0">{{ __($label) }}</p>
                        <p class="text-xl font-black text-slate-800 m-0">{{ number_format((float) ($totals[$key] ?? 0), 1, ',', ' ') }} <span class="text-[10px] text-slate-400">kg</span></p>
                    </div>
                @endforeach
            </div>
            @endif

            {{-- TOURNÉE DE COLLECTE : tous les types en une validation. --}}
            @can('abattoir.C')
            <x-collapsible-form :title="__('Tournée de collecte (tous les types)')" icon="fa-route" :hint="__('sang · plumes · viscères — 1 validation')" :open="$errors->has('rows')">
                <form method="POST" action="{{ route('slaughter.registres.sous_produits.batch') }}">
                    @csrf
                    <div class="space-y-1 mb-4 max-w-xs">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Ordre d'abattage — commun, optionnel") }}</label>
                        <select name="slaughter_order_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("— Aucun —") }}</option>
                            @foreach($recentOrders as $order)
                                <option value="{{ $order->id }}" @selected(old('slaughter_order_id') == $order->id)>{{ $order->order_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                    <th class="px-2 py-2 text-left">{{ __("Type") }}</th>
                                    <th class="px-2 py-2 text-center">{{ __("Quantité (kg)") }}</th>
                                    <th class="px-2 py-2 text-left">{{ __("Destination") }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach(\App\Models\SlaughterByproduct::TYPES as $key => $label)
                                <tr>
                                    <td class="px-2 py-2 text-[10px] font-black text-slate-700 uppercase whitespace-nowrap">{{ __($label) }}</td>
                                    <td class="px-2 py-2 text-center"><input type="number" name="rows[{{ $key }}][quantity_kg]" value="{{ old("rows.{$key}.quantity_kg") }}" step="0.01" min="0.01" placeholder="—" class="w-24 bg-slate-50 border-none rounded-xl p-2 text-base font-black shadow-inner outline-none text-center"></td>
                                    <td class="px-2 py-2">
                                        <select name="rows[{{ $key }}][destination]" class="bg-slate-50 border-none rounded-xl p-2 text-[9px] font-black uppercase shadow-inner outline-none">
                                            @foreach(\App\Models\SlaughterByproduct::DESTINATIONS as $dKey => $dLabel)
                                                <option value="{{ $dKey }}" @selected(old("rows.{$key}.destination", $lastDestByType[$key] ?? 'equarrissage') === $dKey)>{{ __($dLabel) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="mt-4 w-full bg-rose-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-[0.2em] hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-route mr-2"></i> {{ __("Valider la tournée") }}</button>
                </form>
            </x-collapsible-form>

            {{-- SAISIE RAPIDE (unitaire, avec notes) --}}
            <x-collapsible-form :title="__('Enregistrer un sous-produit (unitaire)')" icon="fa-recycle" :hint="__('un type · notes possibles')" :open="$errors->any() && ! $errors->has('rows')">
            <form method="POST" action="{{ route('slaughter.registres.sous_produits.store') }}">
                @csrf
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type *") }}</label>
                        <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("— Sélectionner —") }}</option>
                            @foreach(\App\Models\SlaughterByproduct::TYPES as $key => $label)
                                <option value="{{ $key }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Quantité (kg) *") }}</label>
                        <input type="number" name="quantity_kg" step="0.01" min="0.01" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Destination *") }}</label>
                        <select name="destination" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("— Sélectionner —") }}</option>
                            @foreach(\App\Models\SlaughterByproduct::DESTINATIONS as $key => $label)
                                <option value="{{ $key }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Ordre d'abattage") }}</label>
                        <select name="slaughter_order_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("— Aucun —") }}</option>
                            @foreach($recentOrders as $order)
                                <option value="{{ $order->id }}">{{ $order->order_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes") }}</label>
                        <input type="text" name="notes" maxlength="1000" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">
                    </div>
                    <button type="submit" class="bg-rose-500 text-white p-4 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-plus mr-1"></i> {{ __("Enregistrer") }}</button>
                </div>
            </form>
            </x-collapsible-form>
            @endcan

            {{-- FILTRES --}}
            <form method="GET" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm mb-6 grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Type") }}</label>
                    <select name="type" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                        <option value="">{{ __("Tous") }}</option>
                        @foreach(\App\Models\SlaughterByproduct::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected(request('type') === $key)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Du") }}</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Au") }}</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white p-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-5 py-4 text-left">{{ __("Type") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Quantité (kg)") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Destination") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Ordre") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Notes") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Opérateur") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Collecté le") }}</th>
                            <th class="px-5 py-4 text-center">{{ __("Synchronisé le") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($byproducts as $byproduct)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-5 py-4 text-[10px] font-black text-slate-800 uppercase">{{ __(\App\Models\SlaughterByproduct::TYPES[$byproduct->type] ?? $byproduct->type) }}</td>
                            <td class="px-3 py-4 text-center text-[10px] font-black text-slate-700">{{ number_format((float) $byproduct->quantity_kg, 1, ',', ' ') }}</td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-600 uppercase">{{ __(\App\Models\SlaughterByproduct::DESTINATIONS[$byproduct->destination] ?? $byproduct->destination) }}</td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-500">{{ $byproduct->slaughterOrder?->order_number ?? '—' }}</td>
                            <td class="px-3 py-4 text-[9px] text-slate-500 font-bold max-w-[200px]">{{ $byproduct->notes ?: '—' }}</td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-600 uppercase">{{ $byproduct->operator?->name ?? '—' }}</td>
                            <td class="px-3 py-4 text-center text-[9px] font-black text-slate-600">{{ $byproduct->collected_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-5 py-4 text-center text-[9px] font-black text-slate-400">{{ $byproduct->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-recycle text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucun sous-produit enregistré") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-6">{{ $byproducts->links() }}</div>
        </div>
    </div>
</x-app-layout>
