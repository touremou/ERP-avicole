<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Achats Carburant')" :subtitle="__('Historique des approvisionnements carburant')" icon="fa-gas-pump" accent="orange" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- FORMULAIRE RAPIDE --}}
            @can('ressources.C')
            <div class="bg-orange-50 p-8 rounded-[3rem] border border-orange-200 mb-8">
                <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                    <i class="fa-solid fa-gas-pump"></i> {{ __("Enregistrer un achat") }}
                </h3>
                <form method="POST" action="{{ route('utilities.fuel.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Groupe *") }}</label>
                            <select name="energy_source_id" required class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-sm outline-none appearance-none">
                                @foreach($groupes as $g)
                                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Quantité (L) *") }}</label>
                            <input type="number" name="quantity_liters" step="0.1" min="1" required placeholder="200"
                                class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black text-orange-600 shadow-sm outline-none text-right">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Prix/litre") }} ({{ currency() }}) *</label>
                            <input type="number" name="unit_price" step="100" min="0" required
                                value="{{ setting('energie.fuel_price_liter', 12000) }}"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none text-right">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date *") }}</label>
                            <input type="date" name="purchase_date" value="{{ now()->toDateString() }}" required
                                class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" name="supplier" placeholder="{{ __("Fournisseur / station service") }}"
                            class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                        <input type="text" name="receipt_reference" placeholder="{{ __("N° reçu / bon") }}"
                            class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                    </div>
                    <button type="submit" class="bg-orange-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-orange-600 transition-all border-none cursor-pointer shadow-lg">
                        <i class="fa-solid fa-check mr-2"></i> {{ __("Enregistrer l'achat") }}
                    </button>
                </form>
            </div>
            @endcan

            {{-- HISTORIQUE --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-5 text-left">{{ __("Date") }}</th>
                            <th class="px-4 py-5 text-left">{{ __("Groupe") }}</th>
                            <th class="px-4 py-5 text-right">{{ __("Quantité") }}</th>
                            <th class="px-4 py-5 text-right">{{ __("Prix/L") }}</th>
                            <th class="px-4 py-5 text-right">{{ __("Total") }}</th>
                            <th class="px-4 py-5 text-left">{{ __("Fournisseur") }}</th>
                            <th class="px-6 py-5 text-center">{{ __("Actions") }}</th> {{-- 💡 NOUVELLE COLONNE --}}
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($purchases as $p)
                        <tr class="hover:bg-slate-50/50 transition-all group">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-700">{{ $p->purchase_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $p->source->name ?? '—' }}</td>
                            <td class="px-4 py-4 text-right text-sm font-black text-orange-600">{{ number_format($p->quantity_liters, 0) }} L</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-slate-500">{{ number_format($p->unit_price, 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right text-sm font-black text-slate-900">{{ number_format($p->total_cost, 0, ',', ' ') }} {{ currency() }}</td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-500">{{ $p->supplier ?? '—' }}</td>

                            {{-- 💡 BOUTONS D'ACTION --}}
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2 opacity-20 group-hover:opacity-100 transition-opacity">
                                    <a href="{{ route('utilities.fuel.edit', $p->id) }}" class="w-6 h-6 bg-slate-100 rounded-lg text-slate-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition-colors" title="{{ __("Modifier") }}">
                                        <i class="fa-solid fa-pen text-[9px]"></i>
                                    </a>
                                    <form method="POST" action="{{ route('utilities.fuel.destroy', $p->id) }}" onsubmit='return confirm(@json(__("Supprimer cet enregistrement d'achat ?")));'>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-6 h-6 bg-slate-100 rounded-lg text-slate-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition-colors border-none cursor-pointer" title="{{ __("Supprimer") }}">
                                            <i class="fa-solid fa-trash text-[9px]"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-gas-pump text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucun achat enregistré") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $purchases->links() }}</div>
        </div>
    </div>
</x-app-layout>