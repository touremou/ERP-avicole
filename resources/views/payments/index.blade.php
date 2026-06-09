<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-emerald-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-money-bill-wave text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Encaissements</h2>
                    <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">
                        Caisse du {{ now()->translatedFormat('l d F Y') }}
                    </p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if(session('success'))
                <div class="mb-8 p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- STATS CAISSE DU JOUR --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-slate-900 p-6 rounded-[2rem] text-white text-center shadow-2xl">
                    <p class="text-[8px] font-black text-emerald-400 uppercase tracking-widest mb-2">Total du jour</p>
                    <p class="text-2xl font-black tracking-tighter">{{ number_format($stats['today_total'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] opacity-50">GNF</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-2">
                        <i class="fa-solid fa-money-bills mr-1"></i> Espèces
                    </p>
                    <p class="text-xl font-black text-slate-900">{{ number_format($stats['today_cash'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-400">GNF</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-orange-200 shadow-sm text-center">
                    <p class="text-[8px] font-black text-orange-500 uppercase tracking-widest mb-2">
                        <i class="fa-solid fa-mobile-screen mr-1"></i> Orange Money
                    </p>
                    <p class="text-xl font-black text-slate-900">{{ number_format($stats['today_om'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-400">GNF</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-blue-500 uppercase tracking-widest mb-2">
                        <i class="fa-solid fa-receipt mr-1"></i> Transactions
                    </p>
                    <p class="text-xl font-black text-slate-900">{{ $stats['today_count'] }}</p>
                </div>
            </div>

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <select name="method" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">Tous modes</option>
                    <option value="especes" {{ request('method') === 'especes' ? 'selected' : '' }}>Espèces</option>
                    <option value="orange_money" {{ request('method') === 'orange_money' ? 'selected' : '' }}>Orange Money</option>
                    <option value="virement" {{ request('method') === 'virement' ? 'selected' : '' }}>Virement</option>
                    <option value="cheque" {{ request('method') === 'cheque' ? 'selected' : '' }}>Chèque</option>
                </select>
                <div class="flex items-center gap-2">
                    <span class="text-[9px] font-black text-slate-400 uppercase">Du</span>
                    <input type="date" name="date_from" value="{{ request('date_from') }}"
                        class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black shadow-sm outline-none">
                    <span class="text-[9px] font-black text-slate-400 uppercase">au</span>
                    <input type="date" name="date_to" value="{{ request('date_to') }}"
                        class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black shadow-sm outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">Filtrer</button>
                @if(request()->hasAny(['method', 'date_from', 'date_to']))
                    <a href="{{ route('payments.index') }}" class="text-[9px] font-black text-slate-400 uppercase tracking-widest no-underline hover:text-slate-700">
                        <i class="fa-solid fa-xmark mr-1"></i> Réinitialiser
                    </a>
                @endif
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                <th class="px-6 py-5 text-left">Date</th>
                                <th class="px-4 py-5 text-left">Vente</th>
                                <th class="px-4 py-5 text-left">Client</th>
                                <th class="px-4 py-5 text-center">Mode</th>
                                <th class="px-4 py-5 text-left">Référence</th>
                                <th class="px-4 py-5 text-right">Montant</th>
                                <th class="px-6 py-5 text-left">Reçu par</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($payments as $payment)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-6 py-4">
                                    <p class="text-[10px] font-black text-slate-700">{{ $payment->payment_date->format('d/m/Y') }}</p>
                                    <p class="text-[8px] text-slate-400">{{ $payment->created_at->format('H:i') }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <a href="{{ route('sales.show', $payment->sale) }}" class="text-[10px] font-black text-teal-600 uppercase no-underline hover:text-teal-800">
                                        {{ $payment->sale->reference }}
                                    </a>
                                </td>
                                <td class="px-4 py-4 text-[10px] font-black text-slate-700 uppercase">
                                    {{ $payment->sale->client->name ?? '—' }}
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full tracking-widest',
                                        'bg-emerald-50 text-emerald-600' => $payment->method === 'especes',
                                        'bg-orange-50 text-orange-600' => $payment->method === 'orange_money',
                                        'bg-blue-50 text-blue-600' => $payment->method === 'virement',
                                        'bg-purple-50 text-purple-600' => $payment->method === 'cheque',
                                    ])>
                                        @if($payment->method === 'especes') <i class="fa-solid fa-money-bills mr-1"></i>
                                        @elseif($payment->method === 'orange_money') <i class="fa-solid fa-mobile-screen mr-1"></i>
                                        @elseif($payment->method === 'virement') <i class="fa-solid fa-building-columns mr-1"></i>
                                        @else <i class="fa-solid fa-file-invoice mr-1"></i>
                                        @endif
                                        {{ $payment->method_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-[10px] font-black text-slate-500">
                                    {{ $payment->reference ?? '—' }}
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <p class="text-sm font-black text-emerald-600">+{{ number_format($payment->amount, 0, ',', ' ') }}</p>
                                    <p class="text-[8px] text-slate-400">GNF</p>
                                </td>
                                <td class="px-6 py-4 text-[10px] font-black text-slate-500">
                                    {{ $payment->receiver->name ?? '—' }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-coins text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">Aucun paiement enregistré</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $payments->withQueryString()->links() }}</div>
        </div>
    </div>
</x-app-layout>
