<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'💰 ' . __('Finance')" :subtitle="__('Trésorerie · Dépenses · Achats · Budgets · Comptabilité')" icon="fa-wallet" accent="rose">
            <x-slot name="actions">
                @can('depenses.C')
                <a href="{{ route('expenses.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-rose-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-600 transition-all no-underline shadow-lg italic">
                    <i class="fa-solid fa-plus"></i> {{ __("Nouvelle dépense") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            {{-- KPI — chaque carte porte une mesure principale + un indicateur
                 de pilotage (Δ, autonomie, délai). Gating par périmètre :
                 trésorerie → tresorerie.L, charges/dettes → depenses.L. --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                {{-- Trésorerie + nombre de comptes actifs --}}
                @can('tresorerie.L')
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Trésorerie") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['treasury'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }} · {{ $kpis['accounts_count'] }} {{ __("comptes") }}</p>
                </div>
                @endcan

                {{-- Charges de fonctionnement du mois + Δ vs mois précédent --}}
                @can('depenses.L')
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Charges (mois)") }}</p>
                    <p class="text-2xl font-black text-amber-600 leading-none">{{ number_format($kpis['opex_month'], 0, ',', ' ') }}</p>
                    @if($kpis['opex_delta'] === null)
                        <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                    @else
                        <p @class(['text-[8px] font-black uppercase mt-1', 'text-rose-500' => $kpis['opex_delta'] > 0, 'text-emerald-500' => $kpis['opex_delta'] <= 0])>
                            {{ $kpis['opex_delta'] > 0 ? '▲' : '▼' }} {{ number_format(abs($kpis['opex_delta']), 1, ',', ' ') }}% {{ __("vs m-1") }}
                        </p>
                    @endif
                </div>
                @endcan

                {{-- Dettes fournisseurs + DPO (délai moyen de paiement) --}}
                @can('depenses.L')
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Dettes fournisseurs") }}</p>
                    <p class="text-2xl font-black {{ $kpis['supplier_debt'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($kpis['supplier_debt'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">
                        {{ currency() }}@if($kpis['dpo_days'] !== null) · {{ __("DPO") }} ~{{ $kpis['dpo_days'] }} {{ __("j") }}@endif
                    </p>
                </div>
                @endcan

                {{-- Autonomie de caisse (burn rate) : mois couverts par la trésorerie --}}
                @can('tresorerie.L')
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Autonomie de caisse") }}</p>
                    @if($kpis['runway_months'] === null)
                        <p class="text-2xl font-black text-slate-300 leading-none">—</p>
                        <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("sans charge de réf.") }}</p>
                    @else
                        <p @class(['text-2xl font-black leading-none', 'text-rose-600' => $kpis['runway_months'] < 1, 'text-amber-600' => $kpis['runway_months'] >= 1 && $kpis['runway_months'] < 3, 'text-emerald-600' => $kpis['runway_months'] >= 3])>{{ number_format($kpis['runway_months'], 1, ',', ' ') }}</p>
                        <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("mois de charges") }}</p>
                    @endif
                </div>
                @endcan
            </div>

            {{-- Accès par intention --}}
            @php
                $groups = [
                    ['title' => 'Trésorerie', 'color' => 'emerald', 'items' => [
                        ['label' => 'Comptes & mouvements', 'icon' => 'fa-wallet', 'route' => 'treasury.index', 'can' => 'tresorerie.L'],
                        ['label' => 'Flux de trésorerie', 'icon' => 'fa-chart-line', 'route' => 'treasury.report', 'can' => 'tresorerie.L'],
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

            {{-- Soldes & mouvements de trésorerie : réservés au module Trésorerie. --}}
            @can('tresorerie.L')
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
            @endcan
        </div>
    </div>
</x-app-layout>
