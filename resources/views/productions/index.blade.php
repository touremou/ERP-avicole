<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">🥚 {{ __("Production") }}</h2>
                <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest mt-1 italic leading-none">{{ __("Œufs · Lait · Couvoir") }}</p>
            </div>
            @can('production.C')
            <a href="{{ route('egg-productions.index') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-amber-500 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-amber-600 transition-all no-underline shadow-lg italic"><i class="fa-solid fa-egg"></i> {{ __("Saisir la ponte") }}</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Œufs (jour)") }}</p>
                    <p class="text-2xl font-black text-amber-600 leading-none">{{ number_format($kpis['eggs_today'], 0, ',', ' ') }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Œufs (mois)") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['eggs_month'], 0, ',', ' ') }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Lait (jour)") }}</p>
                    <p class="text-2xl font-black text-cyan-600 leading-none">{{ number_format($kpis['milk_today'], 1, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ __("litres") }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Couvées en cours") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['incub_open'] }}</p>
                </div>
            </div>

            @php
                $groups = [
                    ['title' => 'Production', 'color' => 'amber', 'items' => [
                        ['label' => 'Œufs', 'icon' => 'fa-egg', 'route' => 'egg-productions.index', 'can' => 'production.L'],
                        ['label' => 'Lait', 'icon' => 'fa-bottle-droplet', 'route' => 'milk-productions.index', 'can' => 'production.L'],
                        ['label' => 'Couvoir', 'icon' => 'fa-kiwi-bird', 'route' => 'incubations.index', 'can' => 'production.L'],
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

                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                    <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Pontes récentes") }}</p>
                    <table class="w-full border-collapse mt-3">
                        <tbody class="divide-y divide-slate-50">
                            @forelse($recentEggs as $e)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-6 py-3 text-[9px] font-black text-slate-400 whitespace-nowrap">{{ $e->production_date->format('d/m') }}</td>
                                <td class="px-3 py-3 text-[10px] font-black text-slate-600 uppercase">{{ $e->batch->name ?? $e->batch->code ?? '—' }}</td>
                                <td class="px-6 py-3 text-right text-[10px] font-black text-amber-600">{{ number_format($e->total_eggs_collected, 0, ',', ' ') }} {{ __("œufs") }}</td>
                            </tr>
                            @empty
                            <tr><td class="px-6 py-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucune ponte enregistrée.") }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
