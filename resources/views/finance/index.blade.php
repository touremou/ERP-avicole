<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    💰 {{ __("Finance") }}
                </h2>
                <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ __("Trésorerie · Dépenses · Achats · Budgets") }}
                </p>
            </div>
            @can('depenses.C')
            <a href="{{ route('expenses.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-rose-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-600 transition-all no-underline shadow-lg italic">
                <i class="fa-solid fa-plus"></i> {{ __("Nouvelle dépense") }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Trésorerie") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['treasury'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Dépenses (mois)") }}</p>
                    <p class="text-2xl font-black text-amber-600 leading-none">{{ number_format($kpis['month_expenses'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Dettes fournisseurs") }}</p>
                    <p class="text-2xl font-black {{ $kpis['supplier_debt'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($kpis['supplier_debt'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Comptes trésorerie") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['accounts_count'] }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("actifs") }}</p>
                </div>
            </div>

            {{-- Accès par intention --}}
            @php
                $groups = [
                    ['title' => 'Trésorerie', 'color' => 'emerald', 'items' => [
                        ['label' => 'Comptes & mouvements', 'icon' => 'fa-wallet', 'route' => 'treasury.index', 'can' => 'depenses.L'],
                        ['label' => 'Flux de trésorerie', 'icon' => 'fa-chart-line', 'route' => 'treasury.report', 'can' => 'depenses.L'],
                    ]],
                    ['title' => 'Dépenses', 'color' => 'amber', 'items' => [
                        ['label' => 'Registre', 'icon' => 'fa-receipt', 'route' => 'expenses.index', 'can' => 'depenses.L'],
                        ['label' => 'Nouvelle dépense', 'icon' => 'fa-plus', 'route' => 'expenses.create', 'can' => 'depenses.C'],
                        ['label' => 'Budgets', 'icon' => 'fa-chart-pie', 'route' => 'budgets.index', 'can' => 'depenses.L'],
                    ]],
                    ['title' => 'Achats fournisseurs', 'color' => 'rose', 'items' => [
                        ['label' => 'Journal & dettes', 'icon' => 'fa-file-invoice-dollar', 'route' => 'purchases.index', 'can' => 'depenses.L'],
                        ['label' => 'Nouvel achat', 'icon' => 'fa-cart-plus', 'route' => 'purchases.create', 'can' => 'depenses.C'],
                    ]],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach($groups as $g)
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-{{ $g['color'] }}-500 mb-4">{{ __($g['title']) }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach($g['items'] as $it)
                            @can($it['can'])
                            @if(\Illuminate\Support\Facades\Route::has($it['route']))
                            <a href="{{ route($it['route']) }}" class="flex flex-col items-center justify-center gap-2 p-4 bg-slate-50 rounded-2xl hover:bg-{{ $g['color'] }}-50 hover:text-{{ $g['color'] }}-600 transition-all no-underline text-slate-600 text-center">
                                <i class="fa-solid {{ $it['icon'] }} text-lg"></i>
                                <span class="text-[8px] font-black uppercase tracking-widest leading-tight">{{ __($it['label']) }}</span>
                            </a>
                            @endif
                            @endcan
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Soldes par compte --}}
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Soldes par compte") }}</p>
                    <table class="w-full border-collapse mt-3">
                        <tbody class="divide-y divide-slate-50">
                            @forelse($accounts as $acc)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-6 py-3 text-[10px] font-black text-slate-600 uppercase"><i class="fa-solid {{ $acc->type_icon }} text-slate-300 mr-2"></i>{{ $acc->name }}</td>
                                <td class="px-3 py-3 text-[9px] font-bold text-slate-400 uppercase">{{ $acc->type_label }}</td>
                                <td class="px-6 py-3 text-right text-[11px] font-black text-slate-900">{{ number_format($acc->current_balance, 0, ',', ' ') }}</td>
                            </tr>
                            @empty
                            <tr><td class="px-6 py-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun compte. ") }}<a href="{{ route('treasury.index') }}" class="text-rose-600 no-underline">{{ __("Créer") }}</a></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Mouvements récents --}}
                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-6 pt-5">
                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Mouvements récents") }}</p>
                        <a href="{{ route('treasury.index') }}" class="text-[8px] font-black uppercase tracking-widest text-rose-600 no-underline hover:text-rose-800">{{ __("Trésorerie") }} →</a>
                    </div>
                    <table class="w-full border-collapse mt-3">
                        <tbody class="divide-y divide-slate-50">
                            @forelse($recent as $tx)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-6 py-3 text-[9px] font-black text-slate-400 whitespace-nowrap">{{ $tx->transaction_date->format('d/m') }}</td>
                                <td class="px-3 py-3 text-[10px] font-bold text-slate-500">{{ \Illuminate\Support\Str::limit($tx->description ?? $tx->category, 22) }}</td>
                                <td class="px-6 py-3 text-right text-[10px] font-black {{ $tx->direction === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $tx->direction === 'in' ? '+' : '−' }}{{ number_format($tx->amount, 0, ',', ' ') }}</td>
                            </tr>
                            @empty
                            <tr><td class="px-6 py-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun mouvement.") }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
