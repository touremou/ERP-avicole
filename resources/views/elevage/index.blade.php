<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">🐔 {{ __("Élevage") }}</h2>
                <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mt-1 italic leading-none">{{ __("Cheptel · Santé · Pilotage") }}</p>
            </div>
            @can('elevage.C')
            <a href="{{ route('batches.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all no-underline shadow-lg italic"><i class="fa-solid fa-plus"></i> {{ __("Nouveau lot") }}</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Bâtiments") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['buildings'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Lots actifs") }}</p>
                    <p class="text-2xl font-black text-blue-600 leading-none">{{ $kpis['active_lots'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Effectif vivant") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['livestock'], 0, ',', ' ') }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Lots critiques") }}</p>
                    <p class="text-2xl font-black {{ $kpis['critical'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ $kpis['critical'] }}</p>
                </div>
            </div>

            @php
                $groups = [
                    ['title' => 'Cheptel', 'color' => 'blue', 'items' => [
                        ['label' => 'Bâtiments', 'icon' => 'fa-warehouse', 'route' => 'buildings.index', 'can' => 'elevage.L'],
                        ['label' => 'Lots', 'icon' => 'fa-layer-group', 'route' => 'batches.index', 'can' => 'elevage.L'],
                        ['label' => 'Campagnes', 'icon' => 'fa-flag', 'route' => 'campaigns.index', 'can' => 'elevage.L'],
                    ]],
                    ['title' => 'Santé', 'color' => 'rose', 'items' => [
                        ['label' => 'Suivi sanitaire', 'icon' => 'fa-syringe', 'route' => 'health.index', 'can' => 'elevage.L'],
                        ['label' => 'Protocoles', 'icon' => 'fa-clipboard-list', 'route' => 'protocols.index', 'can' => 'elevage.M'],
                    ]],
                    ['title' => 'Pilotage', 'color' => 'indigo', 'items' => [
                        ['label' => 'Rapports', 'icon' => 'fa-chart-line', 'route' => 'reports.index', 'can' => 'elevage.L'],
                        ['label' => 'Planning', 'icon' => 'fa-calendar-days', 'route' => 'planning.index', 'can' => 'planning.L'],
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

            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Lots à surveiller") }}</p>
                <table class="w-full border-collapse mt-3">
                    <tbody class="divide-y divide-slate-50">
                        @forelse($criticalLots as $b)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black"><a href="{{ route('batches.show', $b) }}" class="text-blue-600 no-underline hover:text-blue-800">{{ $b->name ?? $b->code ?? ('Lot #' . $b->id) }}</a></td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase">{{ $b->building->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-slate-700">{{ number_format($b->current_quantity, 0, ',', ' ') }} {{ __("vivants") }}</td>
                            <td class="px-6 py-3 text-right text-[10px] font-black text-rose-600">{{ number_format($b->qty_dead, 0, ',', ' ') }} {{ __("morts") }}</td>
                        </tr>
                        @empty
                        <tr><td class="px-6 py-8 text-center text-[10px] font-black text-emerald-500 uppercase tracking-widest">{{ __("Aucun lot critique. ✓") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
