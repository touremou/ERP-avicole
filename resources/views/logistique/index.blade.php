<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'📦 ' . __('Logistique')" :subtitle="__('Magasin · Démarque · Expéditions')" icon="fa-boxes-stacked" accent="orange">
            <x-slot name="actions">
                @can('logistique.C')
                <a href="{{ route('stocks.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-orange-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-orange-600 transition-all no-underline shadow-lg italic"><i class="fa-solid fa-plus"></i> {{ __("Nouvel article") }}</a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Valeur du stock") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['stock_value'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Références") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['references'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Sous seuil") }}</p>
                    <p class="text-2xl font-black {{ $kpis['low'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ $kpis['low'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Démarque (mois)") }}</p>
                    <p class="text-2xl font-black {{ $kpis['shrinkage'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($kpis['shrinkage'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
            </div>

            @php
                $groups = [
                    ['title' => 'Magasin', 'color' => 'orange', 'items' => [
                        ['label' => 'Stocks', 'icon' => 'fa-boxes-stacked', 'route' => 'stocks.index', 'can' => 'logistique.L'],
                        ['label' => 'Démarque', 'icon' => 'fa-sliders', 'route' => 'stock-adjustments.index', 'can' => 'logistique.L'],
                    ]],
                    ['title' => 'Expéditions', 'color' => 'blue', 'items' => [
                        ['label' => 'Expéditions', 'icon' => 'fa-truck', 'route' => 'dispatches.index', 'can' => 'logistique.L'],
                        ['label' => 'Écarts', 'icon' => 'fa-triangle-exclamation', 'route' => 'dispatches.discrepancies', 'can' => 'logistique.L'],
                    ]],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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

            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Articles sous seuil") }}</p>
                <table class="w-full border-collapse mt-3">
                    <tbody class="divide-y divide-slate-50">
                        @forelse($lowStocks as $s)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black"><a href="{{ route('stocks.show', $s->id) }}" class="text-orange-600 no-underline hover:text-orange-800">{{ $s->item_name }}</a></td>
                            <td class="px-3 py-3 text-[9px] font-bold text-slate-400 uppercase">{{ $s->category }}</td>
                            <td class="px-6 py-3 text-right text-[10px] font-black text-rose-600">{{ number_format($s->current_quantity, 0, ',', ' ') }} / {{ number_format($s->alert_threshold, 0, ',', ' ') }} {{ $s->unit }}</td>
                        </tr>
                        @empty
                        <tr><td class="px-6 py-8 text-center text-[10px] font-black text-emerald-500 uppercase tracking-widest">{{ __("Tous les stocks sont au-dessus du seuil. ✓") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
