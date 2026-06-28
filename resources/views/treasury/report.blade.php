<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('💰 Flux de trésorerie')" :subtitle="$from->format('d/m/Y') . ' → ' . $to->format('d/m/Y')" icon="fa-chart-line" accent="emerald">
            <x-slot name="actions">
                <a href="{{ route('treasury.report.csv', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'account_id' => $accountId]) }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-50 hover:text-emerald-600 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-file-csv"></i> {{ __("CSV") }}
                </a>
                <a href="{{ route('treasury.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-wallet"></i> {{ __("Comptes") }}
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $currency = currency();
        $catLabels = [
            'vente' => 'Encaissements ventes', 'remboursement' => 'Remboursements clients',
            'depense' => 'Dépenses', 'achat' => 'Règlements fournisseurs', 'avoir_fournisseur' => 'Avoirs fournisseurs',
            'transfert' => 'Transferts', 'cloture_caisse' => 'Clôtures de caisse', 'manuel' => 'Mouvements manuels',
        ];
        $net = $totalIn - $totalOut;
    @endphp

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <x-flash />

            {{-- FILTRES --}}
            <form method="GET" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">{{ __("Du") }}</label>
                    <input type="date" name="from" value="{{ $from->toDateString() }}" class="bg-slate-50 border-none rounded-2xl p-3 text-[11px] font-black shadow-inner outline-none">
                </div>
                <div>
                    <label class="block text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">{{ __("Au") }}</label>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" class="bg-slate-50 border-none rounded-2xl p-3 text-[11px] font-black shadow-inner outline-none">
                </div>
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 ml-1">{{ __("Compte") }}</label>
                    <select name="account_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-[11px] font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                        <option value="">{{ __("Tous les comptes") }}</option>
                        @foreach($accounts as $acc)<option value="{{ $acc->id }}" @selected((int) $accountId === $acc->id)>{{ $acc->name }}</option>@endforeach
                    </select>
                </div>
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer">{{ __("Filtrer") }}</button>
            </form>

            {{-- TOTAUX --}}
            <div class="grid grid-cols-3 gap-4">
                <x-stat-tile :label="__('Entrées')" :value="number_format($totalIn, 0, ',', ' ')" :sub="$currency" accent="emerald" />
                <x-stat-tile :label="__('Sorties')" :value="number_format($totalOut, 0, ',', ' ')" :sub="$currency" accent="rose" />
                <x-stat-tile :label="__('Flux net')" :value="number_format($net, 0, ',', ' ')" :sub="$currency" accent="emerald" :dark="true" />
            </div>

            {{-- PAR CATÉGORIE --}}
            <x-card class="p-0 overflow-hidden">
                <table class="w-full">
                    <thead><tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest">
                        <th class="px-6 py-4 text-left">{{ __("Catégorie") }}</th>
                        <th class="px-4 py-4 text-right">{{ __("Entrées") }}</th>
                        <th class="px-4 py-4 text-right">{{ __("Sorties") }}</th>
                        <th class="px-6 py-4 text-right">{{ __("Net") }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($byCategory as $cat => $v)
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-6 py-3 text-[11px] font-black text-slate-700 uppercase italic">{{ __($catLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat))) }}</td>
                            <td class="px-4 py-3 text-right text-[11px] font-black text-emerald-600">{{ $v['in'] ? '+' . number_format($v['in'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-4 py-3 text-right text-[11px] font-black text-rose-500">{{ $v['out'] ? '-' . number_format($v['out'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-6 py-3 text-right text-[11px] font-black text-slate-900">{{ number_format($v['in'] - $v['out'], 0, ',', ' ') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="p-10 text-center text-[10px] font-black text-slate-300 uppercase tracking-widest italic">{{ __("Aucun mouvement sur la période.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>

            {{-- PAR COMPTE --}}
            @if(! $accountId && $perAccount->isNotEmpty())
            <div>
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4 ml-2">{{ __("Par compte") }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($perAccount as $row)
                    <x-card class="p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-9 h-9 rounded-xl bg-slate-50 flex items-center justify-center text-slate-500"><i class="fa-solid {{ $row['account']->type_icon }}"></i></div>
                            <p class="text-[11px] font-black text-slate-800 uppercase truncate">{{ $row['account']->name }}</p>
                        </div>
                        <div class="flex justify-between text-[10px] font-black">
                            <span class="text-emerald-600">+{{ number_format($row['in'], 0, ',', ' ') }}</span>
                            <span class="text-rose-500">-{{ number_format($row['out'], 0, ',', ' ') }}</span>
                            <span class="text-slate-900">= {{ number_format($row['in'] - $row['out'], 0, ',', ' ') }}</span>
                        </div>
                    </x-card>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
