<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <x-back />
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ $sale->reference }}</h2>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] mt-2 italic {{ $sale->type === 'facture' ? 'text-purple-600' : ($sale->type === 'comptant' ? 'text-emerald-600' : 'text-teal-600') }}">
                        {{ __($sale->type_label) }} — {{ $sale->sale_date->translatedFormat('d F Y') }}
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                @if($sale->status === 'brouillon')
                    <form method="POST" action="{{ route('sales.validate', $sale) }}">
                        @csrf @method('PUT')
                        <button class="bg-emerald-500 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-lg italic border-none cursor-pointer">
                            <i class="fa-solid fa-check-double mr-1"></i> {{ __("Valider & Déstocker") }}
                        </button>
                    </form>
                @endif
                @if($sale->status === 'valide')
                    <form method="POST" action="{{ route('sales.deliver', $sale) }}">
                        @csrf @method('PUT')
                        <button class="bg-blue-500 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg italic border-none cursor-pointer">
                            <i class="fa-solid fa-truck mr-1"></i> {{ __("Marquer Livré") }}
                        </button>
                    </form>
                @endif
                @if(in_array($sale->status, ['valide', 'livre']))
                    @can('commerce.M')
                    <a href="{{ route('sales.return.create', $sale) }}" class="bg-orange-50 border border-orange-200 text-orange-600 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-orange-100 transition-all no-underline flex items-center gap-2">
                        <i class="fa-solid fa-rotate-left"></i> {{ __("Retour") }}
                    </a>
                    @endcan
                @endif
                <a href="{{ route('sales.print', ['sale' => $sale, 'format' => 'a4']) }}" target="_blank" class="bg-white border border-slate-200 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-slate-50 transition-all no-underline flex items-center gap-2">
                    <i class="fa-solid fa-print"></i> {{ __("Imprimer A4") }}
                </a>
                <a href="{{ route('sales.print', ['sale' => $sale, 'format' => 'thermal']) }}" target="_blank" class="bg-white border border-slate-200 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-slate-50 transition-all no-underline flex items-center gap-2">
                    <i class="fa-solid fa-receipt"></i> {{ __("Ticket") }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                {{-- STATUT --}}
                <div @class(['p-6 rounded-[2.5rem] border shadow-sm text-center',
                    'bg-slate-50 border-slate-200' => $sale->status === 'brouillon',
                    'bg-emerald-50 border-emerald-200' => $sale->status === 'valide',
                    'bg-blue-50 border-blue-200' => $sale->status === 'livre',
                    'bg-red-50 border-red-200' => $sale->status === 'annule',
                ])>
                    <p class="text-[8px] font-black uppercase tracking-widest text-slate-400 mb-2">{{ __("Statut") }}</p>
                    <p class="text-xl font-black uppercase italic">{{ ucfirst($sale->status) }}</p>
                </div>

                {{-- TOTAL --}}
                <div class="bg-slate-900 p-6 rounded-[2.5rem] text-white text-center shadow-2xl">
                    <p class="text-[8px] font-black uppercase tracking-widest text-emerald-400 mb-2">{{ __("Total TTC") }}</p>
                    <p class="text-2xl font-black italic tracking-tighter">{{ number_format($sale->total_amount, 0, ',', ' ') }} <small class="text-xs opacity-40">{{ setting('general.currency', 'GNF') }}</small></p>
                </div>

                {{-- PAIEMENT --}}
                <div @class(['p-6 rounded-[2.5rem] border shadow-sm text-center',
                    'bg-red-50 border-red-200' => $sale->payment_status === 'impaye',
                    'bg-amber-50 border-amber-200' => $sale->payment_status === 'partiel',
                    'bg-emerald-50 border-emerald-200' => $sale->payment_status === 'solde',
                ])>
                    <p class="text-[8px] font-black uppercase tracking-widest text-slate-400 mb-2">{{ __("Paiement") }}</p>
                    <p class="text-xl font-black uppercase italic">
                        {{ $sale->payment_status === 'solde' ? __("Soldé") : number_format($sale->remaining_amount, 0, ',', ' ') . ' ' . setting('general.currency', 'GNF') . ' ' . __("dû") }}
                    </p>
                </div>
            </div>

            {{-- CLIENT --}}
            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-teal-50 flex items-center justify-center text-teal-500">
                        <i class="fa-solid fa-user text-lg"></i>
                    </div>
                    <div>
                        <p class="font-black text-lg text-slate-900 uppercase italic leading-none">{{ $sale->client->name }}</p>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">{{ $sale->client->client_id }} — {{ $sale->client->phone ?? __("Pas de tél.") }}</p>
                    </div>
                </div>
                <a href="{{ route('clients.show', $sale->client) }}" class="text-[9px] font-black text-teal-500 uppercase tracking-widest no-underline hover:text-teal-700">
                    {{ __("Voir fiche") }} <i class="fa-solid fa-arrow-right ml-1"></i>
                </a>
            </div>

            {{-- LIGNES --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden mb-6">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-6 py-4 text-left">{{ __("Produit") }}</th>
                            <th class="px-4 py-4 text-center">{{ __("Qté") }}</th>
                            <th class="px-4 py-4 text-center">{{ __("Unité") }}</th>
                            <th class="px-4 py-4 text-right">{{ __("P.U.") }}</th>
                            <th class="px-6 py-4 text-right">{{ __("Total") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($sale->items as $item)
                        <tr>
                            <td class="px-6 py-4">
                                <p class="text-xs font-black text-slate-800 uppercase">{{ $item->product_name }}</p>
                                <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ $item->type_label }}</p>
                            </td>
                            <td class="px-4 py-4 text-center text-sm font-black text-slate-900">{{ $item->quantity }}</td>
                            <td class="px-4 py-4 text-center text-[9px] font-black text-slate-500 uppercase">{{ $item->unit }}</td>
                            <td class="px-4 py-4 text-right text-[10px] font-black text-slate-600">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                            <td class="px-6 py-4 text-right text-sm font-black text-slate-900">{{ number_format($item->total, 0, ',', ' ') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 border-t border-slate-100">
                        <tr>
                            <td colspan="4" class="px-6 py-3 text-right text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Sous-total HT") }}</td>
                            <td class="px-6 py-3 text-right font-black text-slate-900">{{ number_format($sale->subtotal, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</td>
                        </tr>
                        @if($sale->tax_rate > 0)
                        <tr>
                            <td colspan="4" class="px-6 py-3 text-right text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("TVA (:rate%)", ['rate' => $sale->tax_rate]) }}</td>
                            <td class="px-6 py-3 text-right font-black text-slate-900">{{ number_format($sale->tax_amount, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</td>
                        </tr>
                        @endif
                        <tr class="bg-slate-900 text-white">
                            <td colspan="4" class="px-6 py-4 text-right text-[9px] font-black uppercase tracking-widest">{{ __("Total TTC") }}</td>
                            <td class="px-6 py-4 text-right text-lg font-black">{{ number_format($sale->total_amount, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- PAIEMENTS + FORMULAIRE --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Historique paiements --}}
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-money-bill-wave text-emerald-500"></i> {{ __("Encaissements") }}
                    </h3>
                    @forelse($sale->payments as $payment)
                        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl mb-2">
                            <div>
                                <p class="text-xs font-black text-emerald-600">+{{ number_format($payment->amount, 0, ',', ' ') }} {{ setting('general.currency', 'GNF') }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ $payment->method_label }} — {{ $payment->payment_date->translatedFormat('d/m/Y') }}</p>
                            </div>
                            <span class="text-[8px] font-black text-slate-400 italic">{{ $payment->receiver->name ?? '' }}</span>
                        </div>
                    @empty
                        <p class="text-[9px] text-slate-400 uppercase tracking-widest text-center py-4">{{ __("Aucun paiement enregistré") }}</p>
                    @endforelse
                </div>

                {{-- Formulaire nouveau paiement --}}
                @if($sale->payment_status !== 'solde' && !in_array($sale->status, ['brouillon', 'annule']))
                @can('commerce.C')
                @if($hasOpenCashSession ?? false)
                {{-- Encaissement EXPRESS du solde complet → ticket (caisse). N'apparaît
                     que si une session de caisse est ouverte (le paiement passe par la
                     caisse). Sinon, utiliser l'enregistrement de paiement ci-dessous. --}}
                <form method="POST" action="{{ route('pos.encash', $sale) }}" class="bg-teal-50 p-5 rounded-[2.5rem] border border-teal-200 mb-4">
                    @csrf
                    <h3 class="text-[10px] font-black uppercase text-teal-600 tracking-widest mb-2">{{ __("Encaissement express (caisse)") }}</h3>
                    <p class="text-[9px] font-bold text-teal-500 mb-3">{{ __("Solde dû") }} : <span class="font-black">{{ number_format($sale->remaining_amount, 0, ',', ' ') }} {{ currency() }}</span></p>
                    <div class="flex gap-2">
                        <select name="method" required class="flex-1 bg-white border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                            <option value="especes">{{ __("Espèces") }}</option>
                            <option value="orange_money">{{ __("Orange Money") }}</option>
                            <option value="virement">{{ __("Virement") }}</option>
                            <option value="cheque">{{ __("Chèque") }}</option>
                        </select>
                        <button type="submit" class="bg-teal-600 text-white px-5 py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-teal-700 transition-all border-none cursor-pointer shrink-0">
                            <i class="fa-solid fa-cash-register mr-1"></i> {{ __("Solde → ticket") }}
                        </button>
                    </div>
                </form>
                @else
                <a href="{{ route('cash-register.index') }}" class="block bg-slate-50 p-3 rounded-2xl border border-slate-200 mb-4 text-center text-[9px] font-black uppercase tracking-widest text-slate-400 hover:text-teal-600 transition-all no-underline italic">
                    <i class="fa-solid fa-lock mr-1"></i> {{ __("Caisse fermée — ouvrir une session pour l'encaissement express") }}
                </a>
                @endif
                @endcan
                <div class="bg-emerald-50 p-6 rounded-[2.5rem] border border-emerald-200">
                    <h3 class="text-[10px] font-black uppercase text-emerald-600 tracking-widest mb-4">{{ __("Enregistrer un paiement (partiel)") }}</h3>
                    <form method="POST" action="{{ route('payments.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                        <input type="number" name="amount" required min="1" max="{{ $sale->remaining_amount }}" placeholder="{{ __('Montant') }} ({{ setting('general.currency', 'GNF') }})"
                            class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black text-emerald-600 shadow-sm outline-none text-right">
                        <div class="grid grid-cols-2 gap-3">
                            <select name="method" required class="bg-white border-none rounded-2xl p-3 text-[10px] font-black uppercase shadow-sm outline-none">
                                <option value="especes">{{ __("Espèces") }}</option>
                                <option value="orange_money">{{ __("Orange Money") }}</option>
                                <option value="virement">{{ __("Virement") }}</option>
                                <option value="cheque">{{ __("Chèque") }}</option>
                            </select>
                            <input type="date" name="payment_date" value="{{ now()->toDateString() }}" required
                                class="bg-white border-none rounded-2xl p-3 text-[10px] font-black shadow-sm outline-none">
                        </div>
                        <button type="submit" class="w-full bg-emerald-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-check mr-1"></i> {{ __("Encaisser") }}
                        </button>
                    </form>
                </div>
                @endif
            </div>

            {{-- ANNULATION --}}
            @can('commerce.S')
            @if(!in_array($sale->status, ['annule']))
                <div class="mt-8 text-center">
                    <form method="POST" action="{{ route('sales.cancel', $sale) }}" onsubmit="return confirm({{ Js::from(__('Annuler cette vente ? Les stocks seront restaurés.')) }})">
                        @csrf @method('PUT')
                        <input type="text" name="reason" placeholder="{{ __("Motif d'annulation...") }}" required
                            class="bg-white border border-red-200 rounded-2xl px-6 py-3 text-[10px] font-black w-80 mr-3 outline-none">
                        <button class="text-red-500 hover:text-red-700 text-[10px] font-black uppercase tracking-widest border-none bg-transparent cursor-pointer">
                            <i class="fa-solid fa-ban mr-1"></i> {{ __("Annuler cette vente") }}
                        </button>
                    </form>
                </div>
            @endif
            @endcan
        </div>
    </div>
</x-app-layout>
