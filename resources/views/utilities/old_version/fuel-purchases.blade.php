<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('utilities.dashboard') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Achats Gasoil</h2>
                <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mt-2 italic">Historique des approvisionnements carburant</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-8 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- FORMULAIRE RAPIDE --}}
            @can('C')
            <div class="bg-orange-50 p-8 rounded-[3rem] border border-orange-200 mb-8">
                <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                    <i class="fa-solid fa-gas-pump"></i> Enregistrer un achat
                </h3>
                <form method="POST" action="{{ route('utilities.fuel.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Groupe *</label>
                            <select name="energy_source_id" required class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-sm outline-none appearance-none">
                                @foreach($groupes as $g)
                                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Quantité (L) *</label>
                            <input type="number" name="quantity_liters" step="0.1" min="1" required placeholder="200"
                                class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black text-orange-600 shadow-sm outline-none text-right">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Prix/litre (GNF) *</label>
                            <input type="number" name="unit_price" step="100" min="0" required placeholder="12000"
                                class="w-full bg-white border-none rounded-2xl p-4 text-sm font-black shadow-sm outline-none text-right">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Date *</label>
                            <input type="date" name="purchase_date" value="{{ now()->toDateString() }}" required
                                class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" name="supplier" placeholder="Fournisseur / station service"
                            class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                        <input type="text" name="receipt_reference" placeholder="N° reçu / bon"
                            class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black shadow-sm outline-none">
                    </div>
                    <button type="submit" class="bg-orange-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-orange-600 transition-all border-none cursor-pointer shadow-lg">
                        <i class="fa-solid fa-check mr-2"></i> Enregistrer l'achat
                    </button>
                </form>
            </div>
            @endcan

            {{-- HISTORIQUE --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-5 text-left">Date</th>
                            <th class="px-4 py-5 text-left">Groupe</th>
                            <th class="px-4 py-5 text-right">Quantité</th>
                            <th class="px-4 py-5 text-right">Prix/L</th>
                            <th class="px-4 py-5 text-right">Total</th>
                            <th class="px-4 py-5 text-left">Fournisseur</th>
                            <th class="px-6 py-5 text-left">Par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($purchases as $p)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-4 text-[10px] font-black text-slate-700">{{ $p->purchase_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-700 uppercase">{{ $p->source->name ?? '—' }}</td>
                            <td class="px-4 py-4 text-right text-sm font-black text-orange-600">{{ number_format($p->quantity_liters, 0) }} L</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-slate-500">{{ number_format($p->unit_price, 0, ',', ' ') }}</td>
                            <td class="px-4 py-4 text-right text-sm font-black text-slate-900">{{ number_format($p->total_cost, 0, ',', ' ') }} GNF</td>
                            <td class="px-4 py-4 text-[10px] font-black text-slate-500">{{ $p->supplier ?? '—' }}</td>
                            <td class="px-6 py-4 text-[10px] font-black text-slate-400">{{ $p->user->name ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-gas-pump text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">Aucun achat enregistré</p>
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