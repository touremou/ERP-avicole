@php
    /**
     * CONSERVATION — tableau de bord des paris en cours (T2).
     *
     * Deux blocs, dans cet ordre d'importance : les lots suivis avec leurs
     * alertes, puis les stocks conservables SANS suivi — c'est là que se perd
     * l'argent qu'on croyait gagner en attendant.
     */
    $currency = setting('general.currency', 'GNF');
    $severityClass = [
        'critique'  => 'bg-rose-50 text-rose-600',
        'attention' => 'bg-amber-50 text-amber-600',
        'info'      => 'bg-green-50 text-green-600',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Conservation')" :subtitle="__('Lots gardés pour être vendus plus tard')"
                       icon="fa-boxes-stacked" accent="amber" :back="route('logistique.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            @if(session('success'))
                <div class="bg-green-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-rose-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('error') }}</div>
            @endif

            {{-- SYNTHÈSE --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                @php
                    $tiles = [
                        ['label' => __("Lots en conservation"), 'value' => $totals['open_count'], 'tone' => 'neutral'],
                        ['label' => __("Valeur immobilisée"), 'value' => number_format($totals['value_at_cost'], 0, ',', ' '), 'sub' => $currency, 'tone' => 'neutral'],
                        ['label' => __("Freinte cumulée"), 'value' => number_format($totals['shrinkage_kg'], 1, ',', ' '), 'sub' => 'kg', 'tone' => $totals['shrinkage_kg'] > 0 ? 'warn' : 'ok'],
                        ['label' => __("À contrôler"), 'value' => $totals['to_check'], 'tone' => $totals['to_check'] > 0 ? 'warn' : 'ok'],
                        ['label' => __("Échéance dépassée"), 'value' => $totals['past_deadline'], 'tone' => $totals['past_deadline'] > 0 ? 'bad' : 'ok'],
                        ['label' => __("Prix-cible atteint"), 'value' => $totals['target_hit'], 'tone' => $totals['target_hit'] > 0 ? 'sell' : 'neutral'],
                    ];
                    $tileTone = ['ok' => 'text-green-400', 'warn' => 'text-amber-300', 'bad' => 'text-rose-400', 'sell' => 'text-green-300', 'neutral' => 'text-white'];
                @endphp
                @foreach($tiles as $tile)
                    <div class="bg-slate-900 text-white p-5 rounded-[2rem] shadow-lg">
                        <p class="text-[8px] font-black text-white/40 uppercase tracking-widest italic mb-2 leading-tight">{{ $tile['label'] }}</p>
                        <p class="text-xl font-black leading-none {{ $tileTone[$tile['tone']] }}">{{ $tile['value'] }}</p>
                        @isset($tile['sub'])<p class="text-[8px] font-bold text-white/30 uppercase italic mt-2">{{ $tile['sub'] }}</p>@endisset
                    </div>
                @endforeach
            </div>

            {{-- LOTS SUIVIS --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Lots suivis") }}</h3>
                    @can('logistique.C')
                        <a href="{{ route('stored-lots.create') }}" class="text-[9px] font-black uppercase text-amber-600 hover:text-amber-700 italic no-underline">
                            <i class="fa-solid fa-plus mr-1"></i>{{ __("Mettre un stock en conservation") }}
                        </a>
                    @endcan
                </div>

                @forelse($lots as $lot)
                    @php $alerts = $lot->alerts(); @endphp
                    <div class="py-4 border-b border-slate-50 last:border-0">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <a href="{{ route('stored-lots.show', $lot) }}" class="text-[12px] font-black uppercase text-slate-800 italic no-underline hover:text-amber-600">
                                    {{ $lot->label }}
                                </a>
                                <p class="text-[8px] font-bold text-slate-400 uppercase mt-1">
                                    {{ number_format((float) $lot->quantity_current, 1, ',', ' ') }} / {{ number_format((float) $lot->quantity_initial, 1, ',', ' ') }} {{ $lot->unit }}
                                    · {{ __("depuis") }} {{ $lot->days_held }} {{ __("j") }}
                                    @if($lot->hold_until) · {{ __("butoir") }} {{ $lot->hold_until->format('d/m/Y') }} @endif
                                    @unless($lot->is_open) · <span class="text-slate-500">{{ __($lot->status_label) }}</span> @endunless
                                </p>
                            </div>
                            <div class="text-right">
                                @if($lot->shrinkage_percent !== null && $lot->shrinkage_percent > 0)
                                    <p class="text-[10px] font-black {{ $lot->shrinkage_percent >= 10 ? 'text-rose-500' : 'text-amber-600' }}">
                                        −{{ number_format($lot->shrinkage_percent, 1, ',', ' ') }} % {{ __("de freinte") }}
                                    </p>
                                @endif
                                @if($lot->margin_at_market !== null)
                                    {{-- Marge au dernier cours CONSTATÉ, freinte déduite : le seul
                                         chiffre qui dise si le pari est encore gagnant. --}}
                                    <p class="text-[10px] font-black {{ $lot->margin_at_market >= 0 ? 'text-green-600' : 'text-rose-500' }}">
                                        {{ $lot->margin_at_market >= 0 ? '+' : '' }}{{ number_format($lot->margin_at_market, 0, ',', ' ') }} {{ $currency }}
                                        <span class="text-slate-400">{{ __("au cours constaté") }}</span>
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if($alerts)
                            <div class="flex flex-wrap gap-2 mt-3">
                                @foreach($alerts as $alert)
                                    <span class="text-[8px] px-2 py-1 rounded-full font-black uppercase {{ $severityClass[$alert['severity']] ?? 'bg-slate-50 text-slate-500' }}">
                                        {{ $alert['message'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-[10px] font-bold text-slate-300 uppercase italic">{{ __("Aucun lot en conservation.") }}</p>
                @endforelse
            </div>

            {{-- STOCKS SANS SUIVI — le signal le plus utile de la page --}}
            @if($untracked->isNotEmpty())
                <div class="bg-amber-50 border border-amber-100 p-8 rounded-[3rem]">
                    <h3 class="text-[10px] font-black uppercase text-amber-700 tracking-widest italic mb-2">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ __("Stock conservable sans suivi") }}
                    </h3>
                    <p class="text-[9px] font-bold text-amber-600 uppercase italic mb-6 leading-relaxed">
                        {{ __("Cette marchandise dort sans objectif de prix, sans échéance et sans contrôle de conservation. C'est là que se perd l'argent qu'on croyait gagner en attendant.") }}
                    </p>
                    @foreach($untracked as $stock)
                        <div class="flex items-center justify-between py-3 border-b border-amber-100 last:border-0">
                            <div>
                                <p class="text-[11px] font-black uppercase text-slate-800 italic">{{ $stock->item_name }}</p>
                                <p class="text-[8px] font-bold text-amber-600 uppercase mt-1">
                                    {{ number_format((float) $stock->current_quantity, 1, ',', ' ') }} {{ $stock->unit }}
                                    @if($stock->last_unit_price)
                                        · {{ number_format((float) $stock->current_quantity * (float) $stock->last_unit_price, 0, ',', ' ') }} {{ $currency }} {{ __("au coût") }}
                                    @endif
                                </p>
                            </div>
                            @can('logistique.C')
                                <a href="{{ route('stored-lots.create', ['stock_id' => $stock->id]) }}"
                                   class="text-[9px] font-black uppercase text-amber-700 hover:text-amber-900 italic no-underline whitespace-nowrap">
                                    {{ __("Mettre en conservation") }} →
                                </a>
                            @endcan
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
