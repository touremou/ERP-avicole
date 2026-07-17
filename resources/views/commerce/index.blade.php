<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'🛍️ ' . __('Commerce')" :subtitle="__('Vendre · Encaisser · Après-vente')" icon="fa-bag-shopping" accent="teal">
            <x-slot name="actions">
                {{-- Raccourcis Caisse/POS : visibles uniquement si le rôle a le module Caisse. --}}
                @can('caisse.C')
                @if($session)
                    <a href="{{ route('pos.index') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-teal-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all no-underline shadow-lg italic">
                        <i class="fa-solid fa-cash-register"></i> {{ __("Vendre (POS)") }}
                    </a>
                @else
                    <a href="{{ route('cash-register.index') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all no-underline shadow-lg italic">
                        <i class="fa-solid fa-unlock"></i> {{ __("Ouvrir la caisse") }}
                    </a>
                @endif
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            {{-- Alerte session de caisse (réservée au module Caisse) --}}
            @can('caisse.L')
            @if($session)
            <div class="bg-slate-900 text-white p-5 rounded-[2rem] flex flex-col md:flex-row md:items-center justify-between gap-4 not-italic">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-teal-500/20 rounded-2xl flex items-center justify-center text-teal-400"><i class="fa-solid fa-lock-open"></i></div>
                    <div class="text-[11px] font-bold">
                        <span class="text-teal-400 font-black uppercase tracking-widest text-[9px]">{{ __("Caisse ouverte") }}</span><br>
                        {{ __("Par") }} {{ $session->user?->name ?? '—' }} · {{ __("théorique") }} <span class="font-black">{{ number_format($sessionCash, 0, ',', ' ') }} {{ currency() }}</span>
                    </div>
                </div>
                <a href="{{ route('cash-register.index') }}" class="shrink-0 px-5 py-2.5 bg-white text-slate-900 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-teal-100 transition-all no-underline">{{ __("Clôturer / compter") }}</a>
            </div>
            @endif
            @endcan

            {{-- KPI du jour --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("CA du jour") }}</p>
                    <p class="text-2xl font-black text-emerald-600 leading-none">{{ number_format($kpis['ca_jour'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Ventes du jour") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['ventes_jour'] }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("tickets") }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Créances clients") }}</p>
                    <p class="text-2xl font-black {{ $kpis['creances'] > 0 ? 'text-red-600' : 'text-slate-800' }} leading-none">{{ number_format($kpis['creances'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ $kpis['clients_dus'] }} {{ __("clients") }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Trésorerie") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['tresorerie'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
            </div>

            {{-- Accès organisés par INTENTION --}}
            @php
                $groups = [
                    ['title' => 'Vendre', 'color' => 'teal', 'items' => [
                        ['label' => 'Caisse (POS)', 'icon' => 'fa-cash-register', 'route' => 'pos.index', 'can' => 'caisse.C'],
                        ['label' => 'Nouvelle vente', 'icon' => 'fa-file-invoice', 'route' => 'sales.create', 'can' => 'commerce.C'],
                        ['label' => 'Liste des ventes', 'icon' => 'fa-list', 'route' => 'sales.index', 'can' => 'commerce.L'],
                    ]],
                    ['title' => 'Encaisser', 'color' => 'emerald', 'items' => [
                        ['label' => 'Session de caisse', 'icon' => 'fa-box-archive', 'route' => 'cash-register.index', 'can' => 'caisse.L'],
                        ['label' => 'Z de caisse', 'icon' => 'fa-receipt', 'route' => 'pos.report', 'can' => 'caisse.L'],
                        ['label' => 'Paiements', 'icon' => 'fa-money-bill-wave', 'route' => 'payments.index', 'can' => 'commerce.L'],
                    ]],
                    ['title' => 'Après-vente', 'color' => 'orange', 'items' => [
                        ['label' => 'Journal des avoirs', 'icon' => 'fa-rotate-left', 'route' => 'returns.index', 'can' => 'commerce.L'],
                    ]],
                    ['title' => 'Relations', 'color' => 'indigo', 'items' => [
                        ['label' => 'Clients', 'icon' => 'fa-users', 'route' => 'clients.index', 'can' => 'commerce.L'],
                        ['label' => 'Trésorerie', 'icon' => 'fa-wallet', 'route' => 'treasury.index', 'can' => 'depenses.L'],
                    ]],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach($groups as $g)
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-{{ $g['color'] }}-500 mb-4">{{ __($g['title']) }}</p>
                    <div class="grid grid-cols-3 gap-3">
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

            {{-- Ventes récentes --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 pt-5">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Ventes récentes") }}</p>
                    <a href="{{ route('sales.index') }}" class="text-[8px] font-black uppercase tracking-widest text-teal-600 no-underline hover:text-teal-800">{{ __("Tout voir") }} →</a>
                </div>
                <table class="w-full border-collapse mt-3">
                    <tbody class="divide-y divide-slate-50">
                        @forelse($recentSales as $s)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black"><a href="{{ route('sales.show', $s) }}" class="text-teal-600 no-underline hover:text-teal-800">{{ $s->reference }}</a></td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-600 uppercase">{{ $s->client->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-400">{{ $s->sale_date->format('d/m') }}</td>
                            <td class="px-3 py-3 text-center">
                                <span @class(['text-[7px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-red-50 text-red-600' => $s->payment_status === 'impaye',
                                    'bg-amber-50 text-amber-600' => $s->payment_status === 'partiel',
                                    'bg-emerald-50 text-emerald-600' => $s->payment_status === 'solde'])>{{ $s->payment_status }}</span>
                            </td>
                            <td class="px-6 py-3 text-right text-sm font-black text-slate-900">{{ number_format($s->total_amount, 0, ',', ' ') }}</td>
                        </tr>
                        @empty
                        <tr><td class="px-6 py-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucune vente.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
